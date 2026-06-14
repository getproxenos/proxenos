<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Ai\Chat\ChatRespondLoop;
use App\Ai\Chat\ChatRespondRequest;
use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ConversationEventType;
use App\Enum\MembershipRole;
use App\Enum\MessageRole;
use App\Enum\MessageStatus;
use App\Enum\TurnStatus;
use App\Repository\ConversationEventRepository;
use App\Repository\MessageRepository;
use App\Repository\TurnRepository;
use App\Tests\Support\RecordingInMemoryPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 0.3 DoD (handoff §"Definition of done"):
 *  - submit -> persisted assistant reply, real model call via the profile
 *    resolver (here resolved to a test InMemoryPlatform — no live API key);
 *  - full turn written as events; folded into projections;
 *  - multi-turn exchange persists in order;
 *  - the loop service's shape is operation-compatible (ADR-014/023).
 */
final class ChatRespondLoopTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ChatRespondLoop $loop;
    private RecordingInMemoryPlatform $platform;
    private ConversationEventRepository $events;
    private MessageRepository $messages;
    private TurnRepository $turns;
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
        $hash = $hasher->hashPassword(new TransientHashUser(), 'hunter2hunter2');
        $this->user = new User('beau@example.com', $hash, $clock);
        $membership = new Membership($this->user, $this->tenant, MembershipRole::OWNER, $clock);

        $this->em->persist($this->tenant);
        $this->em->persist($this->user);
        $this->em->persist($membership);
        $this->em->flush();

        $this->loop = $container->get(ChatRespondLoop::class);
        $this->platform = $container->get(RecordingInMemoryPlatform::class);
        $this->platform->reset();
        $this->events = $container->get(ConversationEventRepository::class);
        $this->messages = $container->get(MessageRepository::class);
        $this->turns = $container->get(TurnRepository::class);
    }

    public function testEmitsFourEventsInOrderAndFoldsProjection(): void
    {
        $this->platform->setNextReply('hello back');

        $threadId = Uuid::v7();
        $result = $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'hello',
        ));

        self::assertSame('hello back', $result->assistantText);

        $events = $this->events->findByThreadOrdered($threadId);
        self::assertCount(4, $events);
        self::assertSame([
            ConversationEventType::USER_MESSAGE_SUBMITTED,
            ConversationEventType::ASSISTANT_TURN_CREATED,
            ConversationEventType::ASSISTANT_CONTENT_DELTA,
            ConversationEventType::ASSISTANT_TURN_COMPLETED,
        ], array_map(static fn ($e) => $e->getType(), $events));
        self::assertSame([1, 2, 3, 4], array_map(static fn ($e) => $e->getSequence(), $events));

        $this->em->clear();
        $messages = $this->messages->findByThreadOrdered($threadId);
        self::assertCount(2, $messages);

        $userMsg = $messages[0];
        self::assertSame(MessageRole::USER, $userMsg->getRole());
        self::assertSame(MessageStatus::COMPLETE, $userMsg->getStatus());
        self::assertSame(0, $userMsg->getPosition());

        $assistantMsg = $messages[1];
        self::assertSame(MessageRole::ASSISTANT, $assistantMsg->getRole());
        self::assertSame(MessageStatus::COMPLETE, $assistantMsg->getStatus());
        self::assertSame(1, $assistantMsg->getPosition());
        self::assertSame($result->turnId->toRfc4122(), $assistantMsg->getTurnId()?->toRfc4122());

        $turn = $this->turns->find($result->turnId);
        self::assertNotNull($turn);
        self::assertSame(TurnStatus::COMPLETED, $turn->getStatus());
        self::assertNotNull($turn->getCompletedAt());
    }

    public function testResolverPicksConfiguredModelIdAndOptions(): void
    {
        $threadId = Uuid::v7();
        $this->platform->setNextReply('ok');
        $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'first',
        ));

        $calls = $this->platform->calls();
        self::assertCount(1, $calls);
        self::assertSame('claude-sonnet-4-6', $calls[0]['model']);
        self::assertSame(8192, $calls[0]['options']['max_tokens'] ?? null);
    }

    public function testMultiTurnAssemblesPriorMessagesInOrder(): void
    {
        $threadId = Uuid::v7();

        $this->platform->setNextReply('reply-one');
        $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'question one',
        ));

        $this->platform->setNextReply('reply-two');
        $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'question two',
        ));

        $calls = $this->platform->calls();
        self::assertCount(2, $calls);

        // The second call must see the full history: user-1, assistant-1, user-2.
        /** @var MessageBag $bag */
        $bag = $calls[1]['input'];
        self::assertInstanceOf(MessageBag::class, $bag);

        $messages = array_values(iterator_to_array($bag));
        self::assertCount(3, $messages);

        self::assertInstanceOf(UserMessage::class, $messages[0]);
        self::assertSame('question one', $messages[0]->asText());

        self::assertInstanceOf(AssistantMessage::class, $messages[1]);
        self::assertSame('reply-one', $messages[1]->getContent());

        self::assertInstanceOf(UserMessage::class, $messages[2]);
        self::assertSame('question two', $messages[2]->asText());

        $this->em->clear();
        $persisted = $this->messages->findByThreadOrdered($threadId);
        self::assertCount(4, $persisted);
        self::assertSame(
            [MessageRole::USER, MessageRole::ASSISTANT, MessageRole::USER, MessageRole::ASSISTANT],
            array_map(static fn ($m) => $m->getRole(), $persisted)
        );
    }

    public function testEightEventsForTwoTurnExchange(): void
    {
        $threadId = Uuid::v7();
        $this->platform->setNextReply('one');
        $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'first',
        ));

        $this->platform->setNextReply('two');
        $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'second',
        ));

        $events = $this->events->findByThreadOrdered($threadId);
        self::assertCount(8, $events);
        self::assertSame(range(1, 8), array_map(static fn ($e) => $e->getSequence(), $events));
    }
}

final class TransientHashUser implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
