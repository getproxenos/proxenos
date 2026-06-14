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
 * SPA bootstrap endpoint (handoff §3, ADR-026). Asserts:
 *  - returns user + tenant identity from the session cookie;
 *  - emits a CSRF token the SPA echoes on writes;
 *  - sets the mercureAuthorization cookie scoped to the caller's
 *    tenant's threads (enumerated, not wildcarded);
 *  - JSON 401 for anonymous (path covered by ThreadEventsController
 *    test but checked here too because the bootstrap is the SPA's
 *    first call and the 401-vs-302 split is the whole point of the
 *    /api firewall contract).
 */
final class SpaBootstrapControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private EventAppender $appender;
    private Tenant $tenant;
    private User $user;

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

        $this->tenant = new Tenant('personal', 'Personal', $clock);
        $hash = $hasher->hashPassword(new TransientBootUserHash(), 'hunter2hunter2');
        $this->user = new User('boot@example.com', $hash, $clock);
        $this->em->persist($this->tenant);
        $this->em->persist($this->user);
        $this->em->persist(new Membership($this->user, $this->tenant, MembershipRole::OWNER, $clock));
        $this->em->flush();

        $this->appender = $container->get(EventAppender::class);
    }

    public function testBootstrapReturnsIdentityCsrfAndMercureDescriptor(): void
    {
        $threadIdA = $this->seedThread();
        $threadIdB = $this->seedThread();

        $this->loginAs('boot@example.com');
        $this->client->request('GET', '/api/me/bootstrap');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('boot@example.com', $body['user']['email']);
        self::assertSame($this->tenant->getId()->toRfc4122(), $body['tenant']['id']);
        self::assertSame('personal', $body['tenant']['slug']);
        self::assertNotEmpty($body['csrf_token']);

        self::assertSame('http://localhost/.well-known/mercure', $body['mercure']['hub_url']);
        self::assertSame('/threads/{threadId}/events', $body['mercure']['topic_template']);
        self::assertContains('/threads/'.$threadIdA->toRfc4122().'/events', $body['mercure']['subscribed_topics']);
        self::assertContains('/threads/'.$threadIdB->toRfc4122().'/events', $body['mercure']['subscribed_topics']);
        self::assertCount(2, $body['mercure']['subscribed_topics']);

        // mercureAuthorization cookie is set by the bundle's
        // SetCookieSubscriber on kernel.response, sourced from the
        // Authorization::setCookie call in the controller.
        $cookies = $response->headers->getCookies();
        $mercureCookies = array_filter($cookies, static fn ($c) => 'mercureAuthorization' === $c->getName());
        self::assertCount(1, $mercureCookies, 'mercureAuthorization cookie must be attached');
        $cookie = array_values($mercureCookies)[0];
        self::assertTrue($cookie->isHttpOnly(), 'cookie must be HttpOnly so JS cannot exfiltrate');
        self::assertSame('strict', $cookie->getSameSite());
        self::assertNotEmpty($cookie->getValue(), 'cookie must carry the subscriber JWT');
    }

    public function testBootstrapAnonymousReturnsJson401(): void
    {
        $this->client->request('GET', '/api/me/bootstrap');

        $response = $this->client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function testApiChatSubmitRequiresCsrf(): void
    {
        $threadId = $this->seedThread();

        $this->loginAs('boot@example.com');
        // Missing X-CSRF-Token header
        $this->client->request(
            'POST',
            '/api/threads/'.$threadId->toRfc4122().'/messages',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['text' => 'hello'], \JSON_THROW_ON_ERROR),
        );

        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [401, 403], "expected CSRF rejection, got {$status}");
    }

    private function seedThread(): Uuid
    {
        $threadId = Uuid::v7();
        $this->appender->append(new EventEnvelope(
            tenantId: $this->tenant->getId(),
            threadId: $threadId,
            turnId: null,
            actorType: ActorType::USER,
            actorId: $this->user->getId()->toRfc4122(),
            payload: new UserMessageSubmitted(Uuid::v7(), 'hello'),
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

final class TransientBootUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
