<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Functional coverage for the SPA cursor replay endpoint (handoff §1).
 *
 * Asserts:
 *  - happy-path replay returns the four canonical events in sequence ASC,
 *    one envelope shape, payload mirror of the live frame;
 *  - cursor (`after=`) returns strictly-later events;
 *  - pagination caps at `limit` and reports `has_more` + `next_after`;
 *  - tenant isolation: a thread in tenant A is 403 for tenant B;
 *  - anonymous calls get JSON 401, not the form_login redirect.
 *
 * Credential-free — uses the same Tenant/User/Membership scaffold as the
 * existing ChatStreamControllerTest and appends events through the real
 * EventAppender so the serialized envelope matches what the live transport
 * will publish.
 */
final class ThreadEventsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private EventAppender $appender;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE message_parts, messages, turns, threads, conversation_events, memberships, users, tenants RESTART IDENTITY CASCADE',
        );

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $clock = Clock::get();

        $this->tenantA = new Tenant('tenant-a', 'Tenant A', $clock);
        $this->tenantB = new Tenant('tenant-b', 'Tenant B', $clock);
        $hash = $hasher->hashPassword(new TransientApiUserHash(), 'hunter2hunter2');
        $this->userA = new User('alice@example.com', $hash, $clock);
        $this->userB = new User('bob@example.com', $hash, $clock);

        $this->em->persist($this->tenantA);
        $this->em->persist($this->tenantB);
        $this->em->persist($this->userA);
        $this->em->persist($this->userB);
        $this->em->persist(new Membership($this->userA, $this->tenantA, MembershipRole::OWNER, $clock));
        $this->em->persist(new Membership($this->userB, $this->tenantB, MembershipRole::OWNER, $clock));
        $this->em->flush();

        $this->appender = $container->get(EventAppender::class);
    }

    public function testReplayReturnsAllEventsForOwnedThread(): void
    {
        $threadId = $this->appendCanonicalTurn($this->tenantA, $this->userA);

        $this->loginAs('alice@example.com');
        $this->client->request('GET', '/api/threads/'.$threadId->toRfc4122().'/events');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($body['events']);
        self::assertCount(4, $body['events']);
        self::assertSame([1, 2, 3, 4], array_map(static fn ($e) => $e['sequence'], $body['events']));
        self::assertSame(
            ['user_message_submitted', 'assistant_turn_created', 'assistant_content_delta', 'assistant_turn_completed'],
            array_map(static fn ($e) => $e['type'], $body['events']),
        );
        self::assertFalse($body['has_more']);
        self::assertNull($body['next_after']);

        // Envelope shape mirrors what the Mercure publisher will emit:
        // id / sequence / thread_id / turn_id / type / version / actor_*
        // / occurred_at / payload.
        $first = $body['events'][0];
        self::assertSame($threadId->toRfc4122(), $first['thread_id']);
        self::assertSame('user', $first['actor_type']);
        self::assertSame(1, $first['version']);
        self::assertNull($first['turn_id']);
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('occurred_at', $first);
        self::assertSame('hello', $first['payload']['text']);

        // Delta payload carries the cumulative text (ADR-024) and a stable
        // message_id — the SPA reducer keys parts off (message_id, part_index).
        $delta = $body['events'][2];
        self::assertSame('assistant_content_delta', $delta['type']);
        self::assertSame(0, $delta['payload']['part_index']);
        self::assertSame('hi back', $delta['payload']['text']);
    }

    public function testCursorReturnsOnlyEventsStrictlyAfterSequence(): void
    {
        $threadId = $this->appendCanonicalTurn($this->tenantA, $this->userA);

        $this->loginAs('alice@example.com');
        $this->client->request('GET', '/api/threads/'.$threadId->toRfc4122().'/events?after=2');

        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['events']);
        self::assertSame([3, 4], array_map(static fn ($e) => $e['sequence'], $body['events']));
    }

    public function testPaginationCapsAtLimitAndReportsHasMore(): void
    {
        $threadId = $this->appendCanonicalTurn($this->tenantA, $this->userA);

        $this->loginAs('alice@example.com');
        $this->client->request('GET', '/api/threads/'.$threadId->toRfc4122().'/events?limit=2');
        $page1 = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $page1['events']);
        self::assertTrue($page1['has_more']);
        self::assertSame(2, $page1['next_after']);

        $this->client->request('GET', '/api/threads/'.$threadId->toRfc4122().'/events?after='.$page1['next_after'].'&limit=2');
        $page2 = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $page2['events']);
        self::assertFalse($page2['has_more']);
        self::assertNull($page2['next_after']);
        self::assertSame([3, 4], array_map(static fn ($e) => $e['sequence'], $page2['events']));
    }

    public function testIdempotentReplayProducesIdenticalEnvelopesBackToBack(): void
    {
        $threadId = $this->appendCanonicalTurn($this->tenantA, $this->userA);

        $this->loginAs('alice@example.com');
        $this->client->request('GET', '/api/threads/'.$threadId->toRfc4122().'/events');
        $first = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $this->client->request('GET', '/api/threads/'.$threadId->toRfc4122().'/events');
        $second = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame($first, $second, 'replay must be byte-identical for the same cursor — the SPA folds it twice');
    }

    public function testCrossTenantAccessIs403(): void
    {
        $threadId = $this->appendCanonicalTurn($this->tenantA, $this->userA);

        $this->loginAs('bob@example.com');
        $this->client->request('GET', '/api/threads/'.$threadId->toRfc4122().'/events');

        $response = $this->client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('forbidden', $body['error']);
    }

    public function testUnknownThreadIs404(): void
    {
        $this->loginAs('alice@example.com');
        $this->client->request('GET', '/api/threads/'.Uuid::v7()->toRfc4122().'/events');

        $response = $this->client->getResponse();
        self::assertSame(404, $response->getStatusCode());
    }

    public function testAnonymousReturnsJson401(): void
    {
        $this->client->request('GET', '/api/threads/'.Uuid::v7()->toRfc4122().'/events');

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('unauthenticated', $body['error']);
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    private function appendCanonicalTurn(Tenant $tenant, User $user): Uuid
    {
        $threadId = Uuid::v7();
        $turnId = Uuid::v7();
        $userMessageId = Uuid::v7();
        $assistantMessageId = Uuid::v7();

        $this->appender->append(new EventEnvelope(
            tenantId: $tenant->getId(),
            threadId: $threadId,
            turnId: null,
            actorType: ActorType::USER,
            actorId: $user->getId()->toRfc4122(),
            payload: new UserMessageSubmitted($userMessageId, 'hello'),
        ));
        $this->appender->append(new EventEnvelope(
            tenantId: $tenant->getId(),
            threadId: $threadId,
            turnId: $turnId,
            actorType: ActorType::ASSISTANT,
            actorId: null,
            payload: new AssistantTurnCreated(),
        ));
        $this->appender->append(new EventEnvelope(
            tenantId: $tenant->getId(),
            threadId: $threadId,
            turnId: $turnId,
            actorType: ActorType::ASSISTANT,
            actorId: null,
            payload: new AssistantContentDelta($assistantMessageId, 0, 'hi back'),
        ));
        $this->appender->append(new EventEnvelope(
            tenantId: $tenant->getId(),
            threadId: $threadId,
            turnId: $turnId,
            actorType: ActorType::ASSISTANT,
            actorId: null,
            payload: new AssistantTurnCompleted($assistantMessageId),
        ));

        return $threadId;
    }

    private function loginAs(string $email): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => 'hunter2hunter2',
        ]);
        $this->client->submit($form);
    }
}

final class TransientApiUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
