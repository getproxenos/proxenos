<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Ai\Chat\ChatRespondLoop;
use App\Ai\Chat\ChatRespondRequest;
use App\Conversation\ProjectionFolder;
use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Repository\ConversationEventRepository;
use App\Repository\MessagePartRepository;
use App\Repository\MessageRepository;
use App\Repository\ThreadRepository;
use App\Repository\TurnRepository;
use App\Tests\Support\RecordingInMemoryPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Automates the ADR-022 "projections are rebuildable" guarantee under streaming.
 *
 * Procedure (mirrors `app:projections:rebuild` exactly):
 *   1. Run a multi-delta streamed turn through {@see ChatRespondLoop}.
 *   2. Snapshot the resulting projection rows (thread/turn/messages/parts).
 *   3. Truncate ONLY the projection tables, keeping `conversation_events`.
 *   4. Replay every event through `ProjectionFolder` from the repository.
 *   5. Assert the rebuilt projection is byte-identical to the snapshot.
 *
 * The cumulative-replace delta semantics (ADR-024) are the reason this works:
 * each `assistant_content_delta` is a fixed point given the part identity, so
 * any prefix-then-suffix replay converges to the same final state. Without
 * idempotent folds, the second delta would either double-append text or skip
 * the first one; here the result is bit-for-bit identical.
 */
final class ProjectionRebuildAfterStreamingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ChatRespondLoop $loop;
    private RecordingInMemoryPlatform $platform;
    private ConversationEventRepository $events;
    private ProjectionFolder $folder;
    private ThreadRepository $threads;
    private TurnRepository $turns;
    private MessageRepository $messages;
    private MessagePartRepository $parts;
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE message_parts, messages, turns, threads, conversation_events, memberships, users, tenants RESTART IDENTITY CASCADE',
        );

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $clock = Clock::get();

        $this->tenant = new Tenant('personal', 'Personal', $clock);
        $hash = $hasher->hashPassword(new TransientRebuildUserHash(), 'hunter2hunter2');
        $this->user = new User('beau-rebuild@example.com', $hash, $clock);
        $membership = new Membership($this->user, $this->tenant, MembershipRole::OWNER, $clock);

        $this->em->persist($this->tenant);
        $this->em->persist($this->user);
        $this->em->persist($membership);
        $this->em->flush();

        $this->loop = $container->get(ChatRespondLoop::class);
        $this->platform = $container->get(RecordingInMemoryPlatform::class);
        $this->platform->reset();
        $this->events = $container->get(ConversationEventRepository::class);
        $this->folder = $container->get(ProjectionFolder::class);
        $this->threads = $container->get(ThreadRepository::class);
        $this->turns = $container->get(TurnRepository::class);
        $this->messages = $container->get(MessageRepository::class);
        $this->parts = $container->get(MessagePartRepository::class);
    }

    public function testStreamedTurnProjectionRebuildsIdentically(): void
    {
        // Chunks crossing the 32-char threshold so the event log has multiple
        // cumulative-replace deltas + the final flush — the interesting case.
        $this->platform->setNextReplyChunks([
            str_repeat('a', 16),
            str_repeat('b', 16),
            str_repeat('c', 16),
            str_repeat('d', 16),
            str_repeat('e', 6),
        ]);

        $threadId = Uuid::v7();
        $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'rebuild me',
        ));

        // Snapshot the projection BEFORE we wipe it.
        $this->em->clear();
        $before = $this->snapshotThread($threadId);

        $eventsBefore = $this->events->findByThreadOrdered($threadId);
        self::assertNotEmpty($eventsBefore, 'event log must be populated by the loop');

        // Wipe projection tables ONLY. Keep `conversation_events` intact so the
        // rebuild has something to fold from — mirrors what the rebuild command
        // does scoped to a thread.
        $conn = $this->em->getConnection();
        $tid = $threadId->toRfc4122();
        $conn->executeStatement('DELETE FROM message_parts WHERE thread_id = ?', [$tid]);
        $conn->executeStatement('DELETE FROM messages WHERE thread_id = ?', [$tid]);
        $conn->executeStatement('DELETE FROM turns WHERE thread_id = ?', [$tid]);
        $conn->executeStatement('DELETE FROM threads WHERE id = ?', [$tid]);
        $this->em->clear();

        // Sanity: projection actually gone, event log intact.
        self::assertNull($this->threads->find($threadId), 'thread row should be wiped');
        self::assertNotEmpty($this->events->findByThreadOrdered($threadId), 'event log must survive the wipe');

        // Replay through the same ProjectionFolder the write path + the rebuild
        // command both use.
        $eventsToReplay = $this->events->findByThreadOrdered($threadId);
        $this->em->wrapInTransaction(function () use ($eventsToReplay): void {
            foreach ($eventsToReplay as $event) {
                $this->folder->apply($event);
            }
        });
        $this->em->clear();

        $after = $this->snapshotThread($threadId);

        // The whole point of the test: byte-identical.
        self::assertSame($before, $after, 'rebuilt projection must equal the pre-wipe snapshot');
    }

    /**
     * @return array{thread: array<string, mixed>, turns: list<array<string, mixed>>, messages: list<array<string, mixed>>, parts: list<array<string, mixed>>}
     */
    private function snapshotThread(Uuid $threadId): array
    {
        $thread = $this->threads->find($threadId);
        self::assertNotNull($thread, 'thread row must exist before snapshotting');

        $threadRow = [
            'id' => $thread->getId()->toRfc4122(),
            'tenant_id' => $thread->getTenantId()->toRfc4122(),
            'status' => $thread->getStatus(),
            'last_sequence' => $thread->getLastSequence(),
            'title' => $thread->getTitle(),
        ];

        $turnRows = [];
        foreach ($this->turnsForThread($threadId) as $turn) {
            $turnRows[] = [
                'id' => $turn->getId()->toRfc4122(),
                'thread_id' => $turn->getThreadId()->toRfc4122(),
                'tenant_id' => $turn->getTenantId()->toRfc4122(),
                'status' => $turn->getStatus()->value,
                'last_sequence' => $turn->getLastSequence(),
                'completed_at' => $turn->getCompletedAt()?->format(\DATE_ATOM),
            ];
        }

        $messageRows = [];
        $partRows = [];
        foreach ($this->messages->findByThreadOrdered($threadId) as $message) {
            $messageRows[] = [
                'id' => $message->getId()->toRfc4122(),
                'thread_id' => $message->getThreadId()->toRfc4122(),
                'tenant_id' => $message->getTenantId()->toRfc4122(),
                'turn_id' => $message->getTurnId()?->toRfc4122(),
                'role' => $message->getRole()->value,
                'status' => $message->getStatus()->value,
                'position' => $message->getPosition(),
                'last_sequence' => $message->getLastSequence(),
                'completed_at' => $message->getCompletedAt()?->format(\DATE_ATOM),
            ];
            foreach ($this->parts->findByMessageOrdered($message->getId()) as $part) {
                $partRows[] = [
                    'message_id' => $part->getMessageId()->toRfc4122(),
                    'thread_id' => $part->getThreadId()->toRfc4122(),
                    'tenant_id' => $part->getTenantId()->toRfc4122(),
                    'position' => $part->getPosition(),
                    'kind' => $part->getKind(),
                    'content' => $part->getContent(),
                ];
            }
        }

        return [
            'thread' => $threadRow,
            'turns' => $turnRows,
            'messages' => $messageRows,
            'parts' => $partRows,
        ];
    }

    /**
     * @return list<\App\Entity\Turn>
     */
    private function turnsForThread(Uuid $threadId): array
    {
        /** @var list<\App\Entity\Turn> $rows */
        $rows = $this->em->getRepository(\App\Entity\Turn::class)
            ->createQueryBuilder('t')
            ->andWhere('t.threadId = :tid')->setParameter('tid', $threadId)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}

final class TransientRebuildUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
