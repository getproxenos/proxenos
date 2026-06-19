<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Conversation\Event\EventEnvelope;
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
 * HTTP surface for the thread lifecycle (step-03 chunk D2). Asserts:
 *  - GET /api/threads lists the caller tenant's active threads;
 *  - POST .../rename returns 202 and renames (the new title shows in the list);
 *  - POST .../archive returns 202 and soft-hides (drops from the active list);
 *  - a cross-tenant rename/archive is 403 — one tenant can never mutate
 *    another tenant's thread.
 *
 * Credential-free, mirroring ApiDocumentControllerTest: reuses the
 * Tenant/User/Membership scaffold and pulls a live CSRF token from
 * /api/me/bootstrap exactly as the SPA does on its first call.
 */
final class ApiThreadControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private EventAppender $appender;
    private Tenant $tenantA;
    private User $userA;
    private Tenant $tenantB;
    private User $userB;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE thread_attachments, message_parts, messages, turns, threads, conversation_events, memberships, users, tenants RESTART IDENTITY CASCADE',
        );

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $clock = Clock::get();

        $this->tenantA = new Tenant('alpha', 'Alpha', $clock);
        $hashA = $hasher->hashPassword(new TransientThreadUserHash(), 'hunter2hunter2');
        $this->userA = new User('a@example.com', $hashA, $clock);
        $this->em->persist($this->tenantA);
        $this->em->persist($this->userA);
        $this->em->persist(new Membership($this->userA, $this->tenantA, MembershipRole::OWNER, $clock));

        $this->tenantB = new Tenant('beta', 'Beta', $clock);
        $hashB = $hasher->hashPassword(new TransientThreadUserHash(), 'hunter2hunter2');
        $this->userB = new User('b@example.com', $hashB, $clock);
        $this->em->persist($this->tenantB);
        $this->em->persist($this->userB);
        $this->em->persist(new Membership($this->userB, $this->tenantB, MembershipRole::OWNER, $clock));

        $this->em->flush();

        $this->appender = $container->get(EventAppender::class);
    }

    public function testCreateReturns201AndPersistsEmptyThread(): void
    {
        $threadId = Uuid::v7();

        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->client->request(
            'PUT',
            '/api/threads/'.$threadId->toRfc4122(),
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('thread_created', $body['status']);

        // The empty row is in the projection — list it back.
        $this->client->request('GET', '/api/threads');
        $list = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $list);
        self::assertSame($threadId->toRfc4122(), $list[0]['id']);
        self::assertNull($list[0]['title']);
        self::assertSame('active', $list[0]['status']);
    }

    public function testCreateIsIdempotentOnSameTenant(): void
    {
        $threadId = Uuid::v7();

        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->client->request(
            'PUT',
            '/api/threads/'.$threadId->toRfc4122(),
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        // Second PUT must not error or duplicate; it acknowledges existence.
        $this->client->request(
            'PUT',
            '/api/threads/'.$threadId->toRfc4122(),
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('thread_exists', $body['status']);
    }

    public function testCreateOnForeignTenantsThreadIs404(): void
    {
        // Thread already exists under tenant B; user A must not be able to
        // "claim" the same UUID and the response must not confirm B's row.
        $threadId = $this->seedThread($this->tenantB, $this->userB);

        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->client->request(
            'PUT',
            '/api/threads/'.$threadId->toRfc4122(),
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateAllowsSubmittingTheFirstMessage(): void
    {
        // The full first-message flow: pre-create the empty thread, then
        // append user_message_submitted via the appender (the same path the
        // streaming submit takes). The fold's ensureThread short-circuits to
        // the already-created row, the message attaches, and the projection
        // ends up consistent: one user message, position 1.
        $threadId = Uuid::v7();

        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->client->request(
            'PUT',
            '/api/threads/'.$threadId->toRfc4122(),
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->appender->append(new EventEnvelope(
            tenantId: $this->tenantA->getId(),
            threadId: $threadId,
            turnId: null,
            actorType: ActorType::USER,
            actorId: $this->userA->getId()->toRfc4122(),
            payload: new UserMessageSubmitted(Uuid::v7(), 'first message'),
        ));

        $this->client->request('GET', '/api/threads');
        $list = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $list);
        self::assertSame($threadId->toRfc4122(), $list[0]['id']);
    }

    public function testListReturnsActiveThreadsForCallerTenant(): void
    {
        $threadId = $this->seedThread($this->tenantA, $this->userA);
        // A thread under tenant B must never appear in tenant A's list.
        $this->seedThread($this->tenantB, $this->userB);

        $this->loginAs('a@example.com');
        $this->client->request('GET', '/api/threads');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertCount(1, $body);
        self::assertSame($threadId->toRfc4122(), $body[0]['id']);
        self::assertSame('active', $body[0]['status']);
        self::assertArrayHasKey('title', $body[0]);
        self::assertArrayHasKey('updated_at', $body[0]);
    }

    public function testRenameReturns202AndUpdatesTitle(): void
    {
        $threadId = $this->seedThread($this->tenantA, $this->userA);

        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->post('/api/threads/'.$threadId->toRfc4122().'/rename', $csrf, ['title' => 'My renamed thread']);

        self::assertSame(202, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('thread_renamed', $body['status']);

        $this->client->request('GET', '/api/threads');
        $list = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('My renamed thread', $list[0]['title']);
    }

    public function testRenameWithBlankTitleReturns400(): void
    {
        $threadId = $this->seedThread($this->tenantA, $this->userA);

        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->post('/api/threads/'.$threadId->toRfc4122().'/rename', $csrf, ['title' => '   ']);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testArchiveReturns202AndDropsFromActiveList(): void
    {
        $threadId = $this->seedThread($this->tenantA, $this->userA);

        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->client->request(
            'POST',
            '/api/threads/'.$threadId->toRfc4122().'/archive',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertSame(202, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('thread_archived', $body['status']);

        $this->client->request('GET', '/api/threads');
        $list = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(0, $list);
    }

    public function testCrossTenantRenameIs403(): void
    {
        // Thread belongs to tenant B; user A must not be able to rename it.
        $threadId = $this->seedThread($this->tenantB, $this->userB);

        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->post('/api/threads/'.$threadId->toRfc4122().'/rename', $csrf, ['title' => 'hijack']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testCrossTenantArchiveIs403(): void
    {
        $threadId = $this->seedThread($this->tenantB, $this->userB);

        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->client->request(
            'POST',
            '/api/threads/'.$threadId->toRfc4122().'/archive',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    private function seedThread(Tenant $tenant, User $user): Uuid
    {
        $threadId = Uuid::v7();
        $this->appender->append(new EventEnvelope(
            tenantId: $tenant->getId(),
            threadId: $threadId,
            turnId: null,
            actorType: ActorType::USER,
            actorId: $user->getId()->toRfc4122(),
            payload: new UserMessageSubmitted(Uuid::v7(), 'hello'),
        ));

        return $threadId;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $uri, string $csrf, array $payload): void
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function bootstrapCsrfToken(): string
    {
        $this->client->request('GET', '/api/me/bootstrap');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $body['csrf_token'];
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

final class TransientThreadUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
