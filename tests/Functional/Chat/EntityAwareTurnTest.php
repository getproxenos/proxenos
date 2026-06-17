<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Entity\Membership;
use App\Entity\Message;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Enum\MessageStatus;
use App\Tests\Support\RecordingInMemoryPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The step-02 marquee DoD proof (chunk 8): the whole vertical spine end-to-end.
 *
 * Create a `core.document`, attach it to a FRESH thread via the new
 * attach endpoint, fire a chat turn, and assert the captured prompt
 * {@see MessageBag} (recorded by {@see RecordingInMemoryPlatform}) carries
 * BOTH the document's pill-rendered SYSTEM contribution (its title) AND the
 * user message — and that streaming still finishes (assistant Message COMPLETE,
 * i.e. assistant_turn_completed fired).
 *
 * Also exercises the attach/detach endpoints directly: attach happy path,
 * detach happy path, and the 400-on-missing-identity guard.
 *
 * Credential-free: reuses the Tenant/User/Membership scaffold and the live
 * CSRF token from /api/me/bootstrap, exactly as the SPA does.
 */
final class EntityAwareTurnTest extends WebTestCase
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
            'TRUNCATE TABLE thread_attachments, core_documents, message_parts, messages, turns, threads, conversation_events, memberships, users, tenants RESTART IDENTITY CASCADE',
        );

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $clock = Clock::get();

        $tenant = new Tenant('personal', 'Personal', $clock);
        $hash = $hasher->hashPassword(new TransientEntityTurnUserHash(), 'hunter2hunter2');
        $user = new User('attach@example.com', $hash, $clock);
        $membership = new Membership($user, $tenant, MembershipRole::OWNER, $clock);

        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->persist($membership);
        $this->em->flush();

        $this->platform = $container->get(RecordingInMemoryPlatform::class);
        $this->platform->reset();
    }

    public function testAttachedDocumentPillRidesTheTurnPromptAndTurnCompletes(): void
    {
        $this->loginAs('attach@example.com');
        $csrf = $this->bootstrapCsrfToken();

        // 1. Create a real core.document — the slice's "real write".
        $doc = $this->postJson('POST', '/api/documents', $csrf, [
            'title' => 'Quarterly OKRs',
            'body' => 'Ship the typed-context vertical spine end-to-end.',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $docId = $doc['id'];

        // 2. Attach it to a FRESH thread (the thread does not exist yet; the
        //    attach projection only FKs to the tenant, and the turn creates the
        //    thread lazily).
        $threadId = Uuid::v7()->toRfc4122();
        $this->postJson('POST', '/api/threads/'.$threadId.'/attachments', $csrf, [
            'provider' => 'core',
            'type' => 'core.document',
            'id' => $docId,
            'expansion' => 'pill',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        // 3. Fire a chat turn against the fake Platform.
        $this->platform->reset();
        $this->platform->setNextReply('canned-assistant-reply');
        $turn = $this->postJson('POST', '/api/threads/'.$threadId.'/messages', $csrf, [
            'text' => 'summarize my OKRs please',
        ]);
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        // 4. The captured prompt MessageBag carries BOTH the document pill (its
        //    title, in the SYSTEM contribution) AND the user message.
        $calls = $this->platform->calls();
        self::assertCount(1, $calls);
        $bag = $calls[0]['input'];
        self::assertInstanceOf(MessageBag::class, $bag);

        $systemText = $this->systemText($bag);
        self::assertNotNull($systemText, 'expected an entity-context system contribution in the prompt');
        self::assertStringContainsString('Quarterly OKRs', $systemText);

        self::assertStringContainsString('summarize my OKRs please', $this->userText($bag));

        // 5. Streaming still finishes: the assistant Message is COMPLETE
        //    (assistant_turn_completed fired).
        $this->em->clear();
        $assistant = $this->em->getRepository(Message::class)->find(Uuid::fromString($turn['assistant_message_id']));
        self::assertInstanceOf(Message::class, $assistant);
        self::assertSame(MessageStatus::COMPLETE, $assistant->getStatus());
    }

    public function testAttachHappyPathEchoesReference(): void
    {
        $this->loginAs('attach@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $threadId = Uuid::v7()->toRfc4122();
        $body = $this->postJson('POST', '/api/threads/'.$threadId.'/attachments', $csrf, [
            'provider' => 'core',
            'type' => 'core.document',
            'id' => Uuid::v7()->toRfc4122(),
            'expansion' => 'pill',
        ]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame('core', $body['attached']['provider']);
        self::assertSame('core.document', $body['attached']['type']);
        self::assertSame('pill', $body['attached']['expansion']);
    }

    public function testDetachHappyPathIsIdempotent(): void
    {
        $this->loginAs('attach@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $threadId = Uuid::v7()->toRfc4122();
        // Detach a triple that was never attached — must succeed (no-op), not 404.
        $this->client->request(
            'DELETE',
            '/api/threads/'.$threadId.'/attachments',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf],
            content: json_encode([
                'provider' => 'core',
                'type' => 'core.document',
                'id' => Uuid::v7()->toRfc4122(),
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testAttachMissingIdentityReturns400(): void
    {
        $this->loginAs('attach@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $threadId = Uuid::v7()->toRfc4122();
        $body = $this->postJson('POST', '/api/threads/'.$threadId.'/attachments', $csrf, [
            'type' => 'core.document',
            'id' => Uuid::v7()->toRfc4122(),
        ]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('validation_failed', $body['error']);
    }

    private function systemText(MessageBag $bag): ?string
    {
        foreach ($bag->getMessages() as $message) {
            if ($message instanceof SystemMessage) {
                $content = $message->getContent();

                return \is_string($content) ? $content : (string) $content;
            }
        }

        return null;
    }

    private function userText(MessageBag $bag): string
    {
        $buffer = '';
        foreach ($bag->getMessages() as $message) {
            if ($message instanceof UserMessage) {
                $buffer .= (string) $message->asText();
            }
        }

        return $buffer;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function postJson(string $method, string $uri, string $csrf, array $payload): array
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf],
            content: json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        $raw = (string) $this->client->getResponse()->getContent();
        if ('' === $raw) {
            return [];
        }

        return json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
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

final class TransientEntityTurnUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
