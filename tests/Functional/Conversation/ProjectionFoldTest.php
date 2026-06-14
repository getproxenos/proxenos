<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Conversation\Event\EventEnvelope;
use App\Conversation\Event\Payload\AssistantContentDelta;
use App\Conversation\Event\Payload\AssistantTurnCompleted;
use App\Conversation\Event\Payload\AssistantTurnCreated;
use App\Conversation\Event\Payload\UserMessageSubmitted;
use App\Conversation\EventAppender;
use App\Conversation\ProjectionFolder;
use App\Entity\ConversationEvent;
use App\Entity\Membership;
use App\Entity\Message;
use App\Entity\MessagePart;
use App\Entity\Tenant;
use App\Entity\Thread;
use App\Entity\Turn;
use App\Entity\User;
use App\Enum\ActorType;
use App\Enum\MembershipRole;
use App\Enum\MessageRole;
use App\Enum\MessageStatus;
use App\Enum\TurnStatus;
use App\Repository\ConversationEventRepository;
use App\Repository\MessagePartRepository;
use App\Repository\MessageRepository;
use App\Repository\ThreadRepository;
use App\Repository\TurnRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * DoD test for Phase 0.2 (ADR-022). Writes a synthetic four-event sequence
 * via `EventAppender`, asserts the folded projection, then runs
 * `app:projections:rebuild` and asserts the projection reconstructs
 * identically — which is the spec's "projections are rebuildable" guarantee.
 */
final class ProjectionFoldTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EventAppender $appender;
    private ConversationEventRepository $events;
    private ThreadRepository $threadRepo;
    private TurnRepository $turnRepo;
    private MessageRepository $messageRepo;
    private MessagePartRepository $partRepo;
    private Application $console;

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
        $clock = new MockClock('2026-06-15T10:00:00+00:00');

        $this->tenant = new Tenant('personal', 'Personal', $clock);
        $hash = $hasher->hashPassword(new TransientHashUser(), 'hunter2hunter2');
        $this->user = new User('beau@example.com', $hash, $clock);
        $membership = new Membership($this->user, $this->tenant, MembershipRole::OWNER, $clock);
        $this->em->persist($this->tenant);
        $this->em->persist($this->user);
        $this->em->persist($membership);
        $this->em->flush();

        /** @var ConversationEventRepository $eventRepo */
        $eventRepo = $this->em->getRepository(ConversationEvent::class);
        /** @var ThreadRepository $threadRepo */
        $threadRepo = $this->em->getRepository(Thread::class);
        /** @var TurnRepository $turnRepo */
        $turnRepo = $this->em->getRepository(Turn::class);
        /** @var MessageRepository $messageRepo */
        $messageRepo = $this->em->getRepository(Message::class);
        /** @var MessagePartRepository $partRepo */
        $partRepo = $this->em->getRepository(MessagePart::class);

        // Rebuild the appender with a known clock so occurred_at is deterministic.
        $folder = new ProjectionFolder($this->em, $threadRepo, $turnRepo, $messageRepo, $partRepo);
        $this->appender = new EventAppender(
            $this->em,
            $eventRepo,
            $folder,
            new MockClock('2026-06-15T10:00:01+00:00'),
        );
        $this->events = $eventRepo;
        $this->threadRepo = $threadRepo;
        $this->turnRepo = $turnRepo;
        $this->messageRepo = $messageRepo;
        $this->partRepo = $partRepo;

        $this->console = new Application(self::$kernel);
        $this->console->setAutoExit(false);
    }

    public function testFoldsFourEventSequenceIntoProjections(): void
    {
        [$threadId, $turnId, $userMessageId, $assistantMessageId] = $this->appendTextTurn();

        $events = $this->events->findByThreadOrdered($threadId);
        self::assertCount(4, $events);
        self::assertSame([1, 2, 3, 4], array_map(static fn ($e) => $e->getSequence(), $events));

        $this->em->clear();
        $thread = $this->threadRepo->find($threadId);
        self::assertNotNull($thread);
        self::assertSame(4, $thread->getLastSequence());
        self::assertNotNull($thread->getCreatedByUserId());
        self::assertSame($this->user->getId()->toRfc4122(), $thread->getCreatedByUserId()->toRfc4122());

        $turn = $this->turnRepo->find($turnId);
        self::assertNotNull($turn);
        self::assertSame(TurnStatus::COMPLETED, $turn->getStatus());
        self::assertNotNull($turn->getCompletedAt());

        $userMessage = $this->messageRepo->find($userMessageId);
        self::assertNotNull($userMessage);
        self::assertSame(MessageRole::USER, $userMessage->getRole());
        self::assertSame(MessageStatus::COMPLETE, $userMessage->getStatus());
        self::assertSame(0, $userMessage->getPosition());
        self::assertNull($userMessage->getTurnId());

        $assistantMessage = $this->messageRepo->find($assistantMessageId);
        self::assertNotNull($assistantMessage);
        self::assertSame(MessageRole::ASSISTANT, $assistantMessage->getRole());
        self::assertSame(MessageStatus::COMPLETE, $assistantMessage->getStatus());
        self::assertSame(1, $assistantMessage->getPosition());
        self::assertNotNull($assistantMessage->getTurnId());
        self::assertSame($turnId->toRfc4122(), $assistantMessage->getTurnId()->toRfc4122());

        $userPart = $this->partRepo->findOneByMessageAndPosition($userMessageId, 0);
        self::assertNotNull($userPart);
        self::assertSame('hello', $userPart->getContent());

        $assistantPart = $this->partRepo->findOneByMessageAndPosition($assistantMessageId, 0);
        self::assertNotNull($assistantPart);
        self::assertSame('hi back', $assistantPart->getContent());
    }

    public function testRebuildReconstructsIdenticalProjection(): void
    {
        [$threadId, $turnId, $userMessageId, $assistantMessageId] = $this->appendTextTurn();

        $before = $this->snapshot($threadId, $turnId, $userMessageId, $assistantMessageId);

        $this->em->clear();
        $tester = new CommandTester($this->console->find('app:projections:rebuild'));
        $exit = $tester->execute(['thread' => $threadId->toRfc4122()]);
        self::assertSame(0, $exit);

        $this->em->clear();
        $after = $this->snapshot($threadId, $turnId, $userMessageId, $assistantMessageId);

        self::assertSame($before, $after);
    }

    /**
     * @return array{0: Uuid, 1: Uuid, 2: Uuid, 3: Uuid} threadId, turnId, userMessageId, assistantMessageId
     */
    private function appendTextTurn(): array
    {
        $threadId = Uuid::v7();
        $turnId = Uuid::v7();
        $userMessageId = Uuid::v7();
        $assistantMessageId = Uuid::v7();

        $this->appender->append(new EventEnvelope(
            tenantId: $this->tenant->getId(),
            threadId: $threadId,
            turnId: null,
            actorType: ActorType::USER,
            actorId: $this->user->getId()->toRfc4122(),
            payload: new UserMessageSubmitted($userMessageId, 'hello'),
        ));

        $this->appender->append(new EventEnvelope(
            tenantId: $this->tenant->getId(),
            threadId: $threadId,
            turnId: $turnId,
            actorType: ActorType::ASSISTANT,
            actorId: null,
            payload: new AssistantTurnCreated(),
        ));

        $this->appender->append(new EventEnvelope(
            tenantId: $this->tenant->getId(),
            threadId: $threadId,
            turnId: $turnId,
            actorType: ActorType::ASSISTANT,
            actorId: null,
            payload: new AssistantContentDelta($assistantMessageId, 0, 'hi back'),
        ));

        $this->appender->append(new EventEnvelope(
            tenantId: $this->tenant->getId(),
            threadId: $threadId,
            turnId: $turnId,
            actorType: ActorType::ASSISTANT,
            actorId: null,
            payload: new AssistantTurnCompleted($assistantMessageId),
        ));

        return [$threadId, $turnId, $userMessageId, $assistantMessageId];
    }

    /** @return array<string, mixed> */
    private function snapshot(Uuid $threadId, Uuid $turnId, Uuid $userMessageId, Uuid $assistantMessageId): array
    {
        $thread = $this->threadRepo->find($threadId);
        $turn = $this->turnRepo->find($turnId);
        $userMessage = $this->messageRepo->find($userMessageId);
        $assistantMessage = $this->messageRepo->find($assistantMessageId);
        $userPart = $this->partRepo->findOneByMessageAndPosition($userMessageId, 0);
        $assistantPart = $this->partRepo->findOneByMessageAndPosition($assistantMessageId, 0);

        return [
            'thread' => [
                'id' => $thread?->getId()->toRfc4122(),
                'tenant' => $thread?->getTenantId()->toRfc4122(),
                'creator' => $thread?->getCreatedByUserId()?->toRfc4122(),
                'status' => $thread?->getStatus(),
                'last_sequence' => $thread?->getLastSequence(),
            ],
            'turn' => [
                'id' => $turn?->getId()->toRfc4122(),
                'status' => $turn?->getStatus()->value,
                'last_sequence' => $turn?->getLastSequence(),
                'completed' => null !== $turn?->getCompletedAt(),
            ],
            'user_message' => [
                'role' => $userMessage?->getRole()->value,
                'status' => $userMessage?->getStatus()->value,
                'position' => $userMessage?->getPosition(),
                'turn_id' => $userMessage?->getTurnId()?->toRfc4122(),
            ],
            'assistant_message' => [
                'role' => $assistantMessage?->getRole()->value,
                'status' => $assistantMessage?->getStatus()->value,
                'position' => $assistantMessage?->getPosition(),
                'turn_id' => $assistantMessage?->getTurnId()?->toRfc4122(),
            ],
            'user_part' => $userPart?->getContent(),
            'assistant_part' => $assistantPart?->getContent(),
        ];
    }
}

final class TransientHashUser implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
