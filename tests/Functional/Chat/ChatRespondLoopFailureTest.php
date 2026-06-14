<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Ai\Chat\ChatRespondLoop;
use App\Ai\Chat\ChatRespondRequest;
use App\Conversation\ProjectionFolder;
use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ConversationEventType;
use App\Enum\MembershipRole;
use App\Enum\MessageRole;
use App\Enum\MessageStatus;
use App\Enum\TurnStatus;
use App\Repository\ConversationEventRepository;
use App\Repository\MessagePartRepository;
use App\Repository\MessageRepository;
use App\Repository\ThreadRepository;
use App\Repository\TurnRepository;
use App\Tests\Support\RecordingInMemoryPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Exception\RuntimeException as PlatformRuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 0.5 DoD (ADR-025): when the Platform raises mid-loop, ChatRespondLoop
 * must append `assistant_turn_failed` BEFORE rethrowing, so the projection
 * moves the Turn (and any partial assistant Message) to FAILED instead of
 * staying stuck in STREAMING.
 *
 * Covers:
 *  - failure before any delta lands → Turn FAILED, no assistant Message row;
 *  - failure mid-stream after deltas land → Turn FAILED, assistant Message FAILED,
 *    partial cumulative text preserved as durable evidence;
 *  - the user message survives in both cases (we never roll back the user input);
 *  - rebuilding the projection from the event log reproduces the FAILED state
 *    (idempotency under replay).
 */
final class ChatRespondLoopFailureTest extends KernelTestCase
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
        $hash = $hasher->hashPassword(new TransientFailureUserHash(), 'hunter2hunter2');
        $this->user = new User('beau-fail@example.com', $hash, $clock);
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

    public function testFailureBeforeAnyDeltaAppendsFailedEventAndFoldsTurnFailed(): void
    {
        $this->platform->setNextError(new PlatformRuntimeException('upstream auth fail'));

        $threadId = Uuid::v7();
        try {
            $this->loop->execute(new ChatRespondRequest(
                tenantId: $this->tenant->getId(),
                userId: $this->user->getId(),
                threadId: $threadId,
                userMessageText: 'hello',
            ));
            self::fail('loop must rethrow when the platform fails');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Model invocation failed', $e->getMessage());
        }

        $events = $this->events->findByThreadOrdered($threadId);
        $types = array_map(static fn ($e) => $e->getType(), $events);
        self::assertSame(
            [
                ConversationEventType::USER_MESSAGE_SUBMITTED,
                ConversationEventType::ASSISTANT_TURN_CREATED,
                ConversationEventType::ASSISTANT_TURN_FAILED,
            ],
            $types,
            'no content_delta should be present when the failure strikes before any delta'
        );

        $failedPayload = $events[2]->getPayload();
        self::assertSame('platform_error', $failedPayload['finish_reason']);
        self::assertNull($failedPayload['message_id'], 'no assistant Message exists yet, so message_id stays null');
        self::assertStringContainsString('upstream auth fail', (string) $failedPayload['error_summary']);
        self::assertStringNotContainsString('Stack trace', (string) $failedPayload['error_summary']);

        $this->em->clear();
        $turn = $this->turns->find($events[1]->getTurnId());
        self::assertNotNull($turn);
        self::assertSame(TurnStatus::FAILED, $turn->getStatus());

        $messages = $this->messages->findByThreadOrdered($threadId);
        self::assertCount(1, $messages, 'only the user Message exists');
        self::assertSame(MessageRole::USER, $messages[0]->getRole());
        self::assertSame(MessageStatus::COMPLETE, $messages[0]->getStatus(), 'user message survives the failure intact');
    }

    public function testMidStreamFailureFoldsTurnAndMessageFailedAndPreservesPartialText(): void
    {
        // 32-char first chunk: forces a coalesced flush before the throw.
        $this->platform->setNextReplyChunks([
            str_repeat('a', 32),
            str_repeat('b', 32),
            str_repeat('c', 32),
        ]);
        $this->platform->setNextError(new PlatformRuntimeException('connection reset mid-stream'), afterChunks: 2);

        $threadId = Uuid::v7();
        try {
            $this->loop->execute(new ChatRespondRequest(
                tenantId: $this->tenant->getId(),
                userId: $this->user->getId(),
                threadId: $threadId,
                userMessageText: 'hello',
            ));
            self::fail('loop must rethrow when the platform fails mid-stream');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('connection reset mid-stream', $e->getMessage());
        }

        $events = $this->events->findByThreadOrdered($threadId);
        $types = array_map(static fn ($e) => $e->getType(), $events);

        // Expect: USER, TURN_CREATED, at least one DELTA, then TURN_FAILED.
        self::assertSame(ConversationEventType::USER_MESSAGE_SUBMITTED, $types[0]);
        self::assertSame(ConversationEventType::ASSISTANT_TURN_CREATED, $types[1]);
        self::assertSame(ConversationEventType::ASSISTANT_TURN_FAILED, end($types));
        $deltas = array_filter($events, static fn ($e) => ConversationEventType::ASSISTANT_CONTENT_DELTA === $e->getType());
        self::assertGreaterThanOrEqual(1, count($deltas), 'at least one delta lands before the failure');

        // Failed payload references the assistant message that the deltas materialized.
        $failedEvent = end($events);
        $failedPayload = $failedEvent->getPayload();
        self::assertNotNull($failedPayload['message_id'], 'message_id must be set when deltas already landed');
        self::assertSame('platform_error', $failedPayload['finish_reason']);

        $this->em->clear();
        $turn = $this->turns->find($failedEvent->getTurnId());
        self::assertNotNull($turn);
        self::assertSame(TurnStatus::FAILED, $turn->getStatus());

        $messages = $this->messages->findByThreadOrdered($threadId);
        self::assertCount(2, $messages, 'user + partial assistant');
        $assistantMsg = $messages[1];
        self::assertSame(MessageRole::ASSISTANT, $assistantMsg->getRole());
        self::assertSame(MessageStatus::FAILED, $assistantMsg->getStatus());

        // Partial text preserved as durable evidence (cumulative-replace fold).
        $partsRows = $this->parts->findByMessageOrdered($assistantMsg->getId());
        self::assertCount(1, $partsRows);
        self::assertNotSame('', $partsRows[0]->getContent(), 'partial cumulative text from before the throw should survive');
    }

    public function testRebuildFromEventLogReproducesFailedProjection(): void
    {
        $this->platform->setNextReplyChunks([str_repeat('x', 32), str_repeat('y', 32)]);
        $this->platform->setNextError(new PlatformRuntimeException('mid-stream boom'), afterChunks: 1);

        $threadId = Uuid::v7();
        try {
            $this->loop->execute(new ChatRespondRequest(
                tenantId: $this->tenant->getId(),
                userId: $this->user->getId(),
                threadId: $threadId,
                userMessageText: 'rebuild me',
            ));
            self::fail('expected rethrow');
        } catch (\RuntimeException) {
        }

        // Snapshot the FAILED projection.
        $this->em->clear();
        $beforeTurn = $this->turns->findOneBy(['threadId' => $threadId]);
        self::assertNotNull($beforeTurn);
        $before = [
            'turn_status' => $beforeTurn->getStatus()->value,
            'turn_last_sequence' => $beforeTurn->getLastSequence(),
            'messages' => array_map(
                fn ($m) => [
                    'role' => $m->getRole()->value,
                    'status' => $m->getStatus()->value,
                    'position' => $m->getPosition(),
                    'last_sequence' => $m->getLastSequence(),
                ],
                $this->messages->findByThreadOrdered($threadId),
            ),
        ];

        // Wipe projection tables only.
        $conn = $this->em->getConnection();
        $tid = $threadId->toRfc4122();
        $conn->executeStatement('DELETE FROM message_parts WHERE thread_id = ?', [$tid]);
        $conn->executeStatement('DELETE FROM messages WHERE thread_id = ?', [$tid]);
        $conn->executeStatement('DELETE FROM turns WHERE thread_id = ?', [$tid]);
        $conn->executeStatement('DELETE FROM threads WHERE id = ?', [$tid]);
        $this->em->clear();

        // Replay.
        $eventsToReplay = $this->events->findByThreadOrdered($threadId);
        $this->em->wrapInTransaction(function () use ($eventsToReplay): void {
            foreach ($eventsToReplay as $event) {
                $this->folder->apply($event);
            }
        });
        $this->em->clear();

        $afterTurn = $this->turns->findOneBy(['threadId' => $threadId]);
        self::assertNotNull($afterTurn);
        $after = [
            'turn_status' => $afterTurn->getStatus()->value,
            'turn_last_sequence' => $afterTurn->getLastSequence(),
            'messages' => array_map(
                fn ($m) => [
                    'role' => $m->getRole()->value,
                    'status' => $m->getStatus()->value,
                    'position' => $m->getPosition(),
                    'last_sequence' => $m->getLastSequence(),
                ],
                $this->messages->findByThreadOrdered($threadId),
            ),
        ];

        self::assertSame($before, $after, 'rebuilt FAILED projection must equal pre-wipe snapshot');
    }
}

final class TransientFailureUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
