<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\MembershipRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Login round-trip — anon /me 302→/login; valid creds → 302→/me; bad creds
 * → 200 on /login with an error message. Each test TRUNCATEs the three new
 * tables and re-seeds a tenant+user — keeps the suite free of a fixtures
 * dependency, and is more robust than a wrapping transaction (form_login
 * issues session commits mid-request that fight a manual rollback).
 */
final class SecurityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE memberships, users, tenants RESTART IDENTITY CASCADE',
        );

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $clock = Clock::get();

        $tenant = new Tenant('personal', 'Personal', $clock);
        $hash = $hasher->hashPassword(new TransientUserForHashing(), 'hunter2hunter2');
        $user = new User('beau@example.com', $hash, $clock);
        $membership = new Membership($user, $tenant, MembershipRole::OWNER, $clock);

        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->persist($membership);
        $this->em->flush();
    }

    public function testAnonymousMeRedirectsToLogin(): void
    {
        $this->client->request('GET', '/me');
        $response = $this->client->getResponse();

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/login', (string) $response->headers->get('Location'));
    }

    public function testValidCredentialsRedirectToApp(): void
    {
        // Decision 8: the SPA (/app) is now the default post-login target,
        // not the /me placeholder.
        $this->loginAs('beau@example.com', 'hunter2hunter2');

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringEndsWith('/app', $location);
    }

    public function testMePlaceholderStillRendersTenantForAuthedUser(): void
    {
        // The /me placeholder page stays (decision 8) — it is just no longer
        // the post-login target. It still server-renders the user's identity.
        $this->loginAs('beau@example.com', 'hunter2hunter2');

        $this->client->request('GET', '/me');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('beau@example.com', $body);
        self::assertStringContainsString('core://users/', $body);
        self::assertStringContainsString('core://tenants/', $body);
        self::assertStringContainsString('personal', $body);
        self::assertStringContainsString('owner', $body);
    }

    public function testBadCredentialsStayOnLoginWithError(): void
    {
        $this->loginAs('beau@example.com', 'wrong-password');

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringEndsWith('/login', $location);

        $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Invalid credentials', (string) $this->client->getResponse()->getContent());
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

final class TransientUserForHashing implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
