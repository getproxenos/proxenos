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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * SPA entry-point gating (decision 8, ADR-026). Asserts:
 *  - anonymous GET /app → 302 redirect to /login (the main firewall, mirroring
 *    /me — NOT the JSON-401 the /api surface returns);
 *  - an authenticated user gets 200 with the built SPA shell HTML;
 *  - login's default target is now /app (decision 8 flip), not /me.
 *
 * Seeds a tenant+user the same way SecurityTest does (TRUNCATE + re-seed,
 * which survives form_login's mid-request session commits).
 */
final class SpaControllerTest extends WebTestCase
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
        $hash = $hasher->hashPassword(new TransientSpaUserHash(), 'hunter2hunter2');
        $user = new User('spa@example.com', $hash, $clock);
        $membership = new Membership($user, $tenant, MembershipRole::OWNER, $clock);

        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->persist($membership);
        $this->em->flush();
    }

    public function testAnonymousAppRedirectsToLogin(): void
    {
        $this->client->request('GET', '/app');
        $response = $this->client->getResponse();

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/login', (string) $response->headers->get('Location'));
    }

    public function testAnonymousAppSubRouteRedirectsToLogin(): void
    {
        $this->client->request('GET', '/app/threads/0192f000-0000-7000-8000-000000000000');
        $response = $this->client->getResponse();

        self::assertSame(302, $response->getStatusCode());
        self::assertStringEndsWith('/login', (string) $response->headers->get('Location'));
    }

    public function testAuthenticatedUserGetsSpaShell(): void
    {
        $this->loginAs('spa@example.com', 'hunter2hunter2');
        $this->client->request('GET', '/app');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        // The SPA entry doc is streamed (BinaryFileResponse): assert it is the
        // built shell on disk rather than reading the streamed body.
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertStringEndsWith('/public/app/index.html', $response->getFile()->getPathname());
    }

    public function testLoginRedirectsToApp(): void
    {
        $this->loginAs('spa@example.com', 'hunter2hunter2');

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringEndsWith('/app', $location);
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

final class TransientSpaUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
