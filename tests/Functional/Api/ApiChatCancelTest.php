<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Membership;
use App\Entity\Tenant;
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
        $turnId = Uuid::v7();

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
