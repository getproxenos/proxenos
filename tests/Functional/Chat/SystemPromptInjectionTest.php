<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\MembershipRole;
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
 * System-prompt injection end-to-end (step-03 chunk D9, decision 5/7).
 *
 * Asserts the resolved system prompt lands at the FRONT of the captured prompt
 * {@see MessageBag} — ahead of entity context and conversation history — per the
 * ordered contract `[ systemPrompt(0), entityContext(100), conversationHistory ]`.
 *
 * CRITICAL REGRESSION GUARD: a thread with no system prompt AND no attachments
 * yields a MessageBag byte-identical to the step-02 entity-only / no-contribution
 * path — exactly the user message, no injected system segment. This is the
 * proof that D9 is purely additive over step-02's assembly seam.
 */
final class SystemPromptInjectionTest extends WebTestCase
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
        $hash = $hasher->hashPassword(new TransientSystemPromptUserHash(), 'hunter2hunter2');
        $user = new User('persona@example.com', $hash, $clock);
        $membership = new Membership($user, $tenant, MembershipRole::OWNER, $clock);

        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->persist($membership);
        $this->em->flush();

        $this->platform = $container->get(RecordingInMemoryPlatform::class);
        $this->platform->reset();
    }

    public function testSystemPromptRidesFrontAheadOfEntityContext(): void
    {
        $this->loginAs('persona@example.com');
        $csrf = $this->bootstrapCsrfToken();

        // An attached entity (weight-100 system contribution from step-02).
        $doc = $this->json('POST', '/api/documents', $csrf, ['title' => 'Quarterly OKRs', 'body' => 'Ship it.']);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        $threadId = Uuid::v7()->toRfc4122();
        $this->json('POST', '/api/threads/'.$threadId.'/attachments', $csrf, [
            'provider' => 'core',
            'type' => 'core.document',
            'id' => $doc['id'],
            'expansion' => 'pill',
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());

        // A per-thread system-prompt override (weight-0 contribution).
        $this->json('PUT', '/api/threads/'.$threadId.'/system-prompt', $csrf, [
            'system_prompt' => 'You are a terse assistant.',
        ]);
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        $this->platform->reset();
        $this->platform->setNextReply('ok');
        $this->json('POST', '/api/threads/'.$threadId.'/messages', $csrf, ['text' => 'summarize my OKRs please']);
        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        $calls = $this->platform->calls();
        self::assertCount(1, $calls);
        $bag = $calls[0]['input'];
        self::assertInstanceOf(MessageBag::class, $bag);

        $messages = array_values(iterator_to_array($bag));

        // [0] system prompt (weight 0) — strictly ahead of the entity context.
        self::assertInstanceOf(SystemMessage::class, $messages[0]);
        self::assertSame('You are a terse assistant.', (string) $messages[0]->getContent());

        // [1] entity context (weight 100) — the document pill.
        self::assertInstanceOf(SystemMessage::class, $messages[1]);
        self::assertStringContainsString('Quarterly OKRs', (string) $messages[1]->getContent());

        // Conversation history follows.
        self::assertStringContainsString('summarize my OKRs please', $this->userText($bag));
    }

    public function testGlobalDefaultUsedWhenNoOverride(): void
    {
        $this->loginAs('persona@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->json('PUT', '/api/me/settings', $csrf, ['system_prompt_default' => 'Global persona.']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $threadId = Uuid::v7()->toRfc4122();
        $this->platform->reset();
        $this->platform->setNextReply('ok');
        $this->json('POST', '/api/threads/'.$threadId.'/messages', $csrf, ['text' => 'hello']);

        $bag = $this->platform->calls()[0]['input'];
        $messages = array_values(iterator_to_array($bag));

        self::assertInstanceOf(SystemMessage::class, $messages[0]);
        self::assertSame('Global persona.', (string) $messages[0]->getContent());
    }

    public function testThreadOverrideBeatsGlobalDefault(): void
    {
        $this->loginAs('persona@example.com');
        $csrf = $this->bootstrapCsrfToken();

        $this->json('PUT', '/api/me/settings', $csrf, ['system_prompt_default' => 'Global persona.']);
        $threadId = Uuid::v7()->toRfc4122();
        $this->json('PUT', '/api/threads/'.$threadId.'/system-prompt', $csrf, ['system_prompt' => 'Thread override.']);

        $this->platform->reset();
        $this->platform->setNextReply('ok');
        $this->json('POST', '/api/threads/'.$threadId.'/messages', $csrf, ['text' => 'hello']);

        $bag = $this->platform->calls()[0]['input'];
        $messages = array_values(iterator_to_array($bag));

        self::assertInstanceOf(SystemMessage::class, $messages[0]);
        self::assertSame('Thread override.', (string) $messages[0]->getContent());
    }

    public function testNoSystemPromptNoEntityYieldsByteIdenticalUserOnlyBag(): void
    {
        $this->loginAs('persona@example.com');
        $csrf = $this->bootstrapCsrfToken();

        // No global default, no thread override, no attachments — the step-02
        // no-contribution path. The MessageBag must be EXACTLY the user message
        // with no injected system segment.
        $threadId = Uuid::v7()->toRfc4122();
        $this->platform->reset();
        $this->platform->setNextReply('ok');
        $this->json('POST', '/api/threads/'.$threadId.'/messages', $csrf, ['text' => 'just the user message']);

        $bag = $this->platform->calls()[0]['input'];
        $messages = array_values(iterator_to_array($bag));

        self::assertCount(1, $messages, 'no contribution path must inject zero system segments');
        self::assertInstanceOf(UserMessage::class, $messages[0]);
        self::assertSame('just the user message', $messages[0]->asText());
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
    private function json(string $method, string $uri, string $csrf, array $payload): array
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

final class TransientSystemPromptUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
