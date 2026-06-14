<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Tests\Support\RecordingInMemoryPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * HTTP round-trip for the Phase 0.3 chat UI. Logs in as the seeded user,
 * GETs /chat, POSTs a message, and asserts both the assistant reply and the
 * prior user message render on the thread view. The model call goes through
 * {@see RecordingInMemoryPlatform} bound in config/services_test.yaml; no
 * live API key required.
 */
final class ChatControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecordingInMemoryPlatform $platform;

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

        $tenant = new Tenant('personal', 'Personal', $clock);
        $hash = $hasher->hashPassword(new TransientChatUserHash(), 'hunter2hunter2');
        $user = new User('beau@example.com', $hash, $clock);
        $membership = new Membership($user, $tenant, MembershipRole::OWNER, $clock);

        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->persist($membership);
        $this->em->flush();

        $this->platform = $container->get(RecordingInMemoryPlatform::class);
        $this->platform->reset();
    }

    public function testAnonymousChatRedirectsToLogin(): void
    {
        $this->client->request('GET', '/chat');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testSubmittingMessagePersistsTurnAndRendersBoth(): void
    {
        $this->platform->setNextReply('canned-assistant-reply');

        $this->loginAs('beau@example.com', 'hunter2hunter2');
        $this->client->followRedirect(); // /me after login

        $this->client->request('GET', '/chat');
        // GET /chat -> 302 to /chat/new -> 302 to /chat/{threadId}
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/chat', $location);

        // Follow to the show page.
        $crawler = $this->client->followRedirect();
        if (302 === $this->client->getResponse()->getStatusCode()) {
            $crawler = $this->client->followRedirect();
        }
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $form = $crawler->selectButton('Send')->form([
            'text' => 'hello chat',
        ]);
        $this->client->submit($form);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('hello chat', $body);
        self::assertStringContainsString('canned-assistant-reply', $body);

        $calls = $this->platform->calls();
        self::assertCount(1, $calls);
        self::assertSame('claude-sonnet-4-6', $calls[0]['model']);
    }

    private function loginAs(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $password,
        ]);
        $this->client->submit($form);
    }
}

final class TransientChatUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
