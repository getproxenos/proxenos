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
use App\Repository\TurnRepository;
use App\Tests\Support\ControllableTurnCancellation;
use App\Tests\Support\RecordingInMemoryPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cooperative cancellation (step-03 chunk D7, decision 4). When the
 * cross-request TurnCancellation signal trips after a coalesced flush, the loop
 * stops draining the stream and appends a terminal `assistant_turn_cancelled`
 * from a NORMAL return path (not the failure catch), moving the Turn and any
 * partial assistant Message to CANCELLED.
 *
 * Covers:
 *  - exactly one `assistant_turn_cancelled` on the log, no completed/failed;
 *  - Turn CANCELLED + partial assistant Message CANCELLED;
 *  - fewer deltas than a full reply (the stream was cut short);
 *  - the user message survives;
 *  - rebuilding the projection from the event log reproduces CANCELLED state.
 *
 * Credential-free: the in-memory platform streams canned chunks and the
 * cancellation signal is a controllable double (no real cache, no timing race).
 */
final class ChatRespondLoopCancellationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ChatRespondLoop $loop;
    private RecordingInMemoryPlatform $platform;
    private ControllableTurnCancellation $cancellation;
    private ConversationEventRepository $events;
    private ProjectionFolder $folder;
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
        $hash = $hasher->hashPassword(new TransientCancelUserHash(), 'hunter2hunter2');
        $this->user = new User('beau-cancel@example.com', $hash, $clock);
        $membership = new Membership($this->user, $this->tenant, MembershipRole::OWNER, $clock);

        $this->em->persist($this->tenant);
        $this->em->persist($this->user);
        $this->em->persist($membership);
        $this->em->flush();

        $this->loop = $container->get(ChatRespondLoop::class);
        $this->platform = $container->get(RecordingInMemoryPlatform::class);
        $this->platform->reset();
        $this->cancellation = $container->get(ControllableTurnCancellation::class);
        $this->cancellation->reset();
        $this->events = $container->get(ConversationEventRepository::class);
        $this->folder = $container->get(ProjectionFolder::class);
        $this->turns = $container->get(TurnRepository::class);
        $this->messages = $container->get(MessageRepository::class);
        $this->parts = $container->get(MessagePartRepository::class);
    }

    protected function tearDown(): void
    {
        // Disarm so the armed signal cannot leak into another test (e.g. the
        // streaming/failure loop tests) under random execution order.
        $this->cancellation->reset();
        parent::tearDown();
    }

    public function testCooperativeCancelStopsStreamAndFoldsCancelled(): void
    {
        // Three 32-char chunks => a full reply would flush 3 deltas. Trip the
        // signal on the first poll (after the first flush) so the stream stops
        // with a single delta.
        $this->platform->setNextReplyChunks([
            str_repeat('a', 32),
            str_repeat('b', 32),
            str_repeat('c', 32),
        ]);
        $this->cancellation->tripAfterCalls(0);

        $threadId = Uuid::v7();
        $result = $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'hello',
        ));

        // The loop returned a normal result (NOT an exception) carrying only
        // the partial text streamed before the stop.
        self::assertSame(str_repeat('a', 32), $result->assistantText, 'only the pre-cancel partial text survives');

        $events = $this->events->findByThreadOrdered($threadId);
        $types = array_map(static fn ($e) => $e->getType(), $events);

        self::assertSame(ConversationEventType::USER_MESSAGE_SUBMITTED, $types[0]);
        self::assertSame(ConversationEventType::ASSISTANT_TURN_CREATED, $types[1]);
        self::assertSame(ConversationEventType::ASSISTANT_TURN_CANCELLED, end($types));

        // Exactly one cancelled event; never completed, never failed.
        self::assertSame(
            1,
            \count(array_filter($types, static fn ($t) => ConversationEventType::ASSISTANT_TURN_CANCELLED === $t)),
            'exactly one assistant_turn_cancelled on the log',
        );
        self::assertNotContains(ConversationEventType::ASSISTANT_TURN_COMPLETED, $types, 'cancellation must not also complete');
        self::assertNotContains(ConversationEventType::ASSISTANT_TURN_FAILED, $types, 'cancellation is not a failure');

        // Fewer deltas than a full reply (a full 3-chunk run would flush 3).
        $deltas = array_filter($events, static fn ($e) => ConversationEventType::ASSISTANT_CONTENT_DELTA === $e->getType());
        self::assertCount(1, $deltas, 'the stream was cut short after the first coalesced flush');

        // Cancelled payload references the materialized assistant message.
        $cancelledEvent = end($events);
        $cancelledPayload = $cancelledEvent->getPayload();
        self::assertNotNull($cancelledPayload['message_id'], 'message_id is set when a delta already landed');
        self::assertSame('cancelled', $cancelledPayload['finish_reason']);

        $this->em->clear();
        $turn = $this->turns->find($cancelledEvent->getTurnId());
        self::assertNotNull($turn);
        self::assertSame(TurnStatus::CANCELLED, $turn->getStatus());

        $messages = $this->messages->findByThreadOrdered($threadId);
        self::assertCount(2, $messages, 'user + partial assistant');
        self::assertSame(MessageRole::USER, $messages[0]->getRole());
        self::assertSame(MessageStatus::COMPLETE, $messages[0]->getStatus(), 'user message survives the stop');
        $assistantMsg = $messages[1];
        self::assertSame(MessageRole::ASSISTANT, $assistantMsg->getRole());
        self::assertSame(MessageStatus::CANCELLED, $assistantMsg->getStatus());

        // Partial cumulative text preserved as durable evidence.
        $partsRows = $this->parts->findByMessageOrdered($assistantMsg->getId());
        self::assertCount(1, $partsRows);
        self::assertSame(str_repeat('a', 32), $partsRows[0]->getContent());
    }

    public function testRebuildFromEventLogReproducesCancelledProjection(): void
    {
        $this->platform->setNextReplyChunks([
            str_repeat('x', 32),
            str_repeat('y', 32),
            str_repeat('z', 32),
        ]);
        $this->cancellation->tripAfterCalls(0);

        $threadId = Uuid::v7();
        $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'rebuild me',
        ));

        // Snapshot the CANCELLED projection.
        $this->em->clear();
        $beforeTurn = $this->turns->findOneBy(['threadId' => $threadId]);
        self::assertNotNull($beforeTurn);
        self::assertSame(TurnStatus::CANCELLED, $beforeTurn->getStatus());
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

        // Wipe projection tables only, then replay the event log.
        $conn = $this->em->getConnection();
        $tid = $threadId->toRfc4122();
        $conn->executeStatement('DELETE FROM message_parts WHERE thread_id = ?', [$tid]);
        $conn->executeStatement('DELETE FROM messages WHERE thread_id = ?', [$tid]);
        $conn->executeStatement('DELETE FROM turns WHERE thread_id = ?', [$tid]);
        $conn->executeStatement('DELETE FROM threads WHERE id = ?', [$tid]);
        $this->em->clear();

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

        self::assertSame($before, $after, 'rebuilt CANCELLED projection must equal pre-wipe snapshot');
    }
}

final class TransientCancelUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
