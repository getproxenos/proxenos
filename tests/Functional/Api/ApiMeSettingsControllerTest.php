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
 * HTTP surface for system-prompt v0 (step-03 chunk D9, decision 5):
 *  - GET /api/me/settings defaults to null, reflects a prior PUT;
 *  - PUT /api/me/settings sets / blank-clears the global default;
 *  - PUT /api/threads/{id}/system-prompt sets / clears the per-thread override;
 *  - a cross-tenant system-prompt write is 403 (shares ApiThreadController's
 *    thread-belongs-to-tenant guard).
 *
 * Credential-free, mirroring ApiThreadControllerTest's two-tenant scaffold and
 * the live CSRF token from /api/me/bootstrap.
 */
final class ApiMeSettingsControllerTest extends WebTestCase
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
        $hashA = $hasher->hashPassword(new TransientSettingsUserHash(), 'hunter2hunter2');
        $this->userA = new User('a-settings@example.com', $hashA, $clock);
        $this->em->persist($this->tenantA);
        $this->em->persist($this->userA);
        $this->em->persist(new Membership($this->userA, $this->tenantA, MembershipRole::OWNER, $clock));

        $this->tenantB = new Tenant('beta', 'Beta', $clock);
        $hashB = $hasher->hashPassword(new TransientSettingsUserHash(), 'hunter2hunter2');
        $this->userB = new User('b-settings@example.com', $hashB, $clock);
        $this->em->persist($this->tenantB);
        $this->em->persist($this->userB);
        $this->em->persist(new Membership($this->userB, $this->tenantB, MembershipRole::OWNER, $clock));

        $this->em->flush();

        $this->appender = $container->get(EventAppender::class);
    }

    public function testSettingsDefaultIsNullThenReflectsPut(): void
    {
        $this->loginAs('a-settings@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->client->request('GET', '/api/me/settings');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->body()['system_prompt_default']);

        $this->put('/api/me/settings', $csrf, ['system_prompt_default' => 'Be concise.']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('Be concise.', $this->body()['system_prompt_default']);

        $this->client->request('GET', '/api/me/settings');
        self::assertSame('Be concise.', $this->body()['system_prompt_default']);
    }

    public function testBlankPutClearsTheDefault(): void
    {
        $this->loginAs('a-settings@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->put('/api/me/settings', $csrf, ['system_prompt_default' => 'Set first.']);
        self::assertSame('Set first.', $this->body()['system_prompt_default']);

        $this->put('/api/me/settings', $csrf, ['system_prompt_default' => '   ']);
        self::assertNull($this->body()['system_prompt_default']);

        $this->client->request('GET', '/api/me/settings');
        self::assertNull($this->body()['system_prompt_default']);
    }

    public function testThreadSystemPromptSetReturns202(): void
    {
        $threadId = $this->seedThread($this->tenantA, $this->userA);

        $this->loginAs('a-settings@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->put('/api/threads/'.$threadId->toRfc4122().'/system-prompt', $csrf, ['system_prompt' => 'Persona.']);
        self::assertSame(202, $this->client->getResponse()->getStatusCode());
        self::assertSame('thread_system_prompt_set', $this->body()['status']);
    }

    public function testThreadSystemPromptAcceptsNullToClear(): void
    {
        $threadId = $this->seedThread($this->tenantA, $this->userA);

        $this->loginAs('a-settings@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->put('/api/threads/'.$threadId->toRfc4122().'/system-prompt', $csrf, ['system_prompt' => null]);
        self::assertSame(202, $this->client->getResponse()->getStatusCode());
        self::assertSame('thread_system_prompt_set', $this->body()['status']);
    }

    public function testCrossTenantSystemPromptIs403(): void
    {
        $threadId = $this->seedThread($this->tenantB, $this->userB);

        $this->loginAs('a-settings@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->put('/api/threads/'.$threadId->toRfc4122().'/system-prompt', $csrf, ['system_prompt' => 'hijack']);
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
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function put(string $uri, string $csrf, array $payload): void
    {
        $this->client->request(
            'PUT',
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

final class TransientSettingsUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
