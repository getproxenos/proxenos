<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Conversation\Event\EventEnvelope;
use App\Conversation\Event\Payload\AssistantContentDelta;
use App\Conversation\Event\Payload\AssistantTurnCompleted;
use App\Conversation\Event\Payload\AssistantTurnCreated;
use App\Conversation\Event\Payload\UserMessageSubmitted;
use App\Conversation\EventAppender;
use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ActorType;
use App\Enum\MembershipRole;
use App\Tests\Support\RecordingMercureHub;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Mercure\Update;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Mercure fan-out (handoff §2). Asserts that every appended
 * `ConversationEvent` is published to the per-thread topic with the same
 * envelope shape the replay endpoint serves.
 *
 * Credential-free: the test container replaces `mercure.hub.default` with
 * an in-process `RecordingMercureHub` (config/services_test.yaml). No real
 * broker is reachable; CI passes without one.
 */
final class MercurePublishingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EventAppender $appender;
    private RecordingMercureHub $hub;
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
        $hash = $hasher->hashPassword(new TransientPubUserHash(), 'hunter2hunter2');
        $this->user = new User('publisher@example.com', $hash, $clock);
        $this->em->persist($this->tenant);
        $this->em->persist($this->user);
        $this->em->persist(new Membership($this->user, $this->tenant, MembershipRole::OWNER, $clock));
        $this->em->flush();

        $this->appender = $container->get(EventAppender::class);
        $this->hub = $container->get(RecordingMercureHub::class);
        $this->hub->reset();
    }

    public function testEveryAppendedEventPublishesToPerThreadTopic(): void
    {
        $threadId = Uuid::v7();
        $turnId = Uuid::v7();
        $userMsgId = Uuid::v7();
        $assistantMsgId = Uuid::v7();

        $this->appender->append(new EventEnvelope(
            tenantId: $this->tenant->getId(),
            threadId: $threadId,
            turnId: null,
            actorType: ActorType::USER,
            actorId: $this->user->getId()->toRfc4122(),
            payload: new UserMessageSubmitted($userMsgId, 'hello'),
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
            payload: new AssistantContentDelta($assistantMsgId, 0, 'hi back'),
        ));
        $this->appender->append(new EventEnvelope(
            tenantId: $this->tenant->getId(),
            threadId: $threadId,
            turnId: $turnId,
            actorType: ActorType::ASSISTANT,
            actorId: null,
            payload: new AssistantTurnCompleted($assistantMsgId),
        ));

        $published = $this->hub->published();
        self::assertCount(4, $published, 'one Mercure update per appended event');

        $topic = '/threads/'.$threadId->toRfc4122().'/events';
        $expectedTypes = [
            'user_message_submitted',
            'assistant_turn_created',
            'assistant_content_delta',
            'assistant_turn_completed',
        ];
        $expectedSequences = [1, 2, 3, 4];

        foreach ($published as $i => $update) {
            self::assertInstanceOf(Update::class, $update);
            self::assertSame([$topic], $update->getTopics(), 'each event must publish to the per-thread topic only');
            self::assertTrue($update->isPrivate(), 'updates must be private; subscriber JWT scopes who can read');

            $payload = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame($expectedTypes[$i], $payload['type']);
            self::assertSame($expectedSequences[$i], $payload['sequence']);
            self::assertSame($threadId->toRfc4122(), $payload['thread_id']);
            // Envelope keys must match the replay endpoint exactly — the SPA
            // reducer folds live + replay through one union (handoff §1/§2).
            self::assertSame(
                ['id', 'sequence', 'thread_id', 'turn_id', 'type', 'version', 'actor_type', 'actor_id', 'occurred_at', 'payload'],
                array_keys($payload),
            );
        }

        // The delta carries the cumulative text and stable message/part keys
        // — replay folds two arrivals of the same event idempotently.
        $delta = json_decode($published[2]->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('assistant_content_delta', $delta['type']);
        self::assertSame(0, $delta['payload']['part_index']);
        self::assertSame('hi back', $delta['payload']['text']);
    }

    public function testPublishHappensAfterCommitOnly(): void
    {
        // No events appended yet — nothing should be published.
        self::assertCount(0, $this->hub->published());

        $threadId = Uuid::v7();
        $this->appender->append(new EventEnvelope(
            tenantId: $this->tenant->getId(),
            threadId: $threadId,
            turnId: null,
            actorType: ActorType::USER,
            actorId: $this->user->getId()->toRfc4122(),
            payload: new UserMessageSubmitted(Uuid::v7(), 'hello'),
        ));

        self::assertCount(1, $this->hub->published(), 'publish only after wrapInTransaction commits');
    }
}

final class TransientPubUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
