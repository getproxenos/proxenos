<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\Thread;
use App\Entity\Turn;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Tests\Support\ControllableTurnCancellation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cancel endpoint wiring (step-03 chunk D7). The `POST …/cancel` stub now sets
 * the cross-request TurnCancellation signal after the tenancy guard, keeping
 * its 202 `cancel_requested` response. The concurrently streaming loop polls
 * that signal (covered by ChatRespondLoopCancellationTest); here we assert the
 * endpoint records the request.
 *
 * Credential-free: the cancellation store is the controllable test double
 * (config/services_test.yaml); CSRF is pulled from /api/me/bootstrap exactly as
 * the SPA does.
 */
final class ApiChatCancelTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ControllableTurnCancellation $cancellation;
    private Uuid $tenantId;

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

        $tenant = new Tenant('alpha', 'Alpha', $clock);
        $hash = $hasher->hashPassword(new TransientCancelEndpointUserHash(), 'hunter2hunter2');
        $user = new User('a@example.com', $hash, $clock);
        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->persist(new Membership($user, $tenant, MembershipRole::OWNER, $clock));
        $this->em->flush();

        $this->tenantId = $tenant->getId();

        $this->cancellation = $container->get(ControllableTurnCancellation::class);
        $this->cancellation->reset();
    }

    protected function tearDown(): void
    {
        $this->cancellation->reset();
        parent::tearDown();
    }

    public function testCancelEndpointSetsTheSignalAndReturns202(): void
    {
        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $threadId = Uuid::v7();
        $turnId = $this->persistTurn($threadId, $this->tenantId);

        self::assertFalse($this->cancellation->isRequested($turnId), 'no signal before the request');

        $this->client->request(
            'POST',
            '/api/threads/'.$threadId->toRfc4122().'/runs/'.$turnId->toRfc4122().'/cancel',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertSame(202, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('cancel_requested', $body['status']);

        self::assertTrue($this->cancellation->isRequested($turnId), 'the endpoint records the cooperative-cancel request');
    }

    public function testCancelRejectsAnUnknownTurnWithoutSettingTheSignal(): void
    {
        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $threadId = Uuid::v7();
        $turnId = Uuid::v7(); // never persisted

        $this->client->request(
            'POST',
            '/api/threads/'.$threadId->toRfc4122().'/runs/'.$turnId->toRfc4122().'/cancel',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->cancellation->isRequested($turnId), 'an unknown turn must never set the signal');
    }

    public function testCancelRejectsATurnThatBelongsToADifferentThread(): void
    {
        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        // The turn is real and same-tenant, but the route names another thread.
        $turnThreadId = Uuid::v7();
        $turnId = $this->persistTurn($turnThreadId, $this->tenantId);
        $routeThreadId = Uuid::v7();

        $this->client->request(
            'POST',
            '/api/threads/'.$routeThreadId->toRfc4122().'/runs/'.$turnId->toRfc4122().'/cancel',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->cancellation->isRequested($turnId), 'a turn under another thread must not be cancellable via a mismatched route');
    }

    public function testCancelRejectsATurnOwnedByAnotherTenant(): void
    {
        // A victim turn the attacker has the UUID for, owned by a foreign tenant.
        $foreignTenant = new Tenant('beta', 'Beta', Clock::get());
        $this->em->persist($foreignTenant);
        $this->em->flush();

        $threadId = Uuid::v7();
        $turnId = $this->persistTurn($threadId, $foreignTenant->getId());

        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->client->request(
            'POST',
            '/api/threads/'.$threadId->toRfc4122().'/runs/'.$turnId->toRfc4122().'/cancel',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->cancellation->isRequested($turnId), 'cross-tenant possession of a turn UUID must not cancel the run');
    }

    /**
     * Persist a Turn projection plus the backing Thread it references (the
     * `fk_turns_thread` constraint requires the thread to exist). The tenant
     * must already exist — `threads.tenant_id` is FK-constrained.
     */
    private function persistTurn(Uuid $threadId, Uuid $tenantId): Uuid
    {
        $turnId = Uuid::v7();
        $now = Clock::get()->now();
        $this->em->persist(new Thread($threadId, $tenantId, null, $now));
        $this->em->persist(new Turn($turnId, $threadId, $tenantId, 1, $now));
        $this->em->flush();
        $this->em->clear();

        return $turnId;
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

final class TransientCancelEndpointUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
