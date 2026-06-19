<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Conversation\Title\HeuristicThreadTitleSource;
use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ConversationEventType;
use App\Enum\MembershipRole;
use App\Repository\ConversationEventRepository;
use App\Repository\ThreadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Auto-title behavior on the submit path (step-03 chunk D4). Asserts:
 *  - the first message on a fresh thread yields a non-empty title via a
 *    `thread_renamed` event (the heuristic of the first user message);
 *  - a second message does NOT re-title (still exactly one `thread_renamed`);
 *  - the heuristic clamps a long first message to the 200-char column.
 *
 * Credential-free: the chat loop resolves `proxenos.task.chat` to the in-memory
 * test platform (config/services_test.yaml), and CSRF is pulled from
 * /api/me/bootstrap exactly as the SPA does — mirroring ApiThreadControllerTest.
 */
final class ApiChatAutoTitleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ConversationEventRepository $events;
    private ThreadRepository $threads;
    private Tenant $tenant;

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

        $this->tenant = new Tenant('alpha', 'Alpha', $clock);
        $hash = $hasher->hashPassword(new TransientAutoTitleUserHash(), 'hunter2hunter2');
        $user = new User('a@example.com', $hash, $clock);
        $this->em->persist($this->tenant);
        $this->em->persist($user);
        $this->em->persist(new Membership($user, $this->tenant, MembershipRole::OWNER, $clock));
        $this->em->flush();

        $this->events = $container->get(ConversationEventRepository::class);
        $this->threads = $container->get(ThreadRepository::class);
    }

    public function testFirstMessageAutoTitlesViaThreadRenamed(): void
    {
        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();
        $threadId = Uuid::v7();

        $this->submit($threadId, $csrf, '  Plan the   quarterly  roadmap  ');

        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        self::assertSame(1, $this->countRenamedEvents($threadId));

        $this->em->clear();
        $thread = $this->threads->find($threadId);
        self::assertNotNull($thread);
        self::assertSame('Plan the quarterly roadmap', $thread->getTitle());
    }

    public function testSecondMessageDoesNotReTitle(): void
    {
        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();
        $threadId = Uuid::v7();

        $this->submit($threadId, $csrf, 'First message becomes the title');
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        $this->submit($threadId, $csrf, 'A different second message');
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // Still exactly one rename — the title was set once and never recomputed.
        self::assertSame(1, $this->countRenamedEvents($threadId));

        $this->em->clear();
        $thread = $this->threads->find($threadId);
        self::assertNotNull($thread);
        self::assertSame('First message becomes the title', $thread->getTitle());
    }

    public function testLongFirstMessageIsClampedToTwoHundredChars(): void
    {
        $this->loginAs('a@example.com');
        $csrf = $this->bootstrapCsrfToken();
        $threadId = Uuid::v7();

        $this->submit($threadId, $csrf, str_repeat('a', 250));
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $thread = $this->threads->find($threadId);
        self::assertNotNull($thread);
        $title = $thread->getTitle();
        self::assertNotNull($title);
        self::assertSame(HeuristicThreadTitleSource::MAX_TITLE_LENGTH, mb_strlen($title));
        self::assertStringEndsWith('…', $title);
    }

    private function countRenamedEvents(Uuid $threadId): int
    {
        $renamed = array_filter(
            $this->events->findByThreadOrdered($threadId),
            static fn ($e) => ConversationEventType::THREAD_RENAMED === $e->getType(),
        );

        return \count($renamed);
    }

    private function submit(Uuid $threadId, string $csrf, string $text): void
    {
        $this->client->request(
            'POST',
            '/api/threads/'.$threadId->toRfc4122().'/messages',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf],
            content: json_encode(['text' => $text], \JSON_THROW_ON_ERROR),
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

final class TransientAutoTitleUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
