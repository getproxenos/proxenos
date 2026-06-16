<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\TypedEntity\Core\Document\CoreDocumentDeclaration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * HTTP CRUD for `core.document` (step-02 chunk 5, decision 10) — the slice's
 * "real write" that closes the vertical spine. Asserts:
 *  - happy-path POST returns 201 with {id, envelope, data};
 *  - GET round-trips the same shape with the persisted data;
 *  - GET of an unknown (or cross-tenant, hence dangling) id is 404.
 *
 * Credential-free: reuses the Tenant/User/Membership scaffold from the other
 * functional tests and pulls a live CSRF token from /api/me/bootstrap, exactly
 * as the SPA does on its first call.
 */
final class ApiDocumentControllerTest extends WebTestCase
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
            'TRUNCATE TABLE core_documents, message_parts, messages, turns, threads, conversation_events, memberships, users, tenants RESTART IDENTITY CASCADE',
        );

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $clock = Clock::get();

        $tenant = new Tenant('personal', 'Personal', $clock);
        $hash = $hasher->hashPassword(new TransientDocUserHash(), 'hunter2hunter2');
        $user = new User('doc@example.com', $hash, $clock);
        $membership = new Membership($user, $tenant, MembershipRole::OWNER, $clock);

        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->persist($membership);
        $this->em->flush();
    }

    public function testCreateDocumentReturns201WithEnvelopeAndData(): void
    {
        $this->loginAs('doc@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $body = $this->postDocument($csrf, [
            'title' => 'My first doc',
            'body' => 'Hello, world.',
            'tags' => ['alpha', 'beta'],
            'collection' => 'notes',
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $body['id']);
        self::assertSame(new CoreDocumentDeclaration()->envelope(), $body['envelope']);
        self::assertSame('My first doc', $body['data']['title']);
        self::assertSame('Hello, world.', $body['data']['body']);
        self::assertSame(['alpha', 'beta'], $body['data']['tags']);
        self::assertSame('notes', $body['data']['collection']);
    }

    public function testGetDocumentReturns200WithIdEnvelopeAndData(): void
    {
        $this->loginAs('doc@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $created = $this->postDocument($csrf, [
            'title' => 'Roundtrip',
            'body' => 'persisted body',
        ]);
        $id = $created['id'];

        $this->client->request('GET', '/api/documents/'.$id);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame($id, $body['id']);
        self::assertSame(new CoreDocumentDeclaration()->envelope(), $body['envelope']);
        self::assertSame('Roundtrip', $body['data']['title']);
        self::assertSame('persisted body', $body['data']['body']);
        self::assertSame([], $body['data']['tags']);
        self::assertNull($body['data']['collection']);
    }

    public function testGetUnknownDocumentReturns404(): void
    {
        $this->loginAs('doc@example.com');

        $this->client->request('GET', '/api/documents/'.Uuid::v7()->toRfc4122());

        $response = $this->client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('not_found', $body['error']);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function postDocument(string $csrf, array $payload): array
    {
        $this->client->request(
            'POST',
            '/api/documents',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
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

final class TransientDocUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
