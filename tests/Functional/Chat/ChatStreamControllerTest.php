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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Phase 0.4: SSE endpoint streams cumulative-text deltas to the Twig chat UI.
 *
 * The whole loop runs synchronously through the test InMemoryPlatform here, so
 * coalescing + flush logic is exercised end-to-end. Real provider streaming
 * (Anthropic over HTTPS) is left to live verification — see the PR test plan.
 */
final class ChatStreamControllerTest extends WebTestCase
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
        $hash = $hasher->hashPassword(new TransientStreamCtrlUserHash(), 'hunter2hunter2');
        $user = new User('beau-sse@example.com', $hash, $clock);
        $membership = new Membership($user, $tenant, MembershipRole::OWNER, $clock);

        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->persist($membership);
        $this->em->flush();

        $this->platform = $container->get(RecordingInMemoryPlatform::class);
        $this->platform->reset();
    }

    public function testStreamEndpointEmitsSseDeltasAndDone(): void
    {
        // 70 chars across chunks crossing the 32-char flush threshold twice.
        $this->platform->setNextReplyChunks([
            str_repeat('a', 16),
            str_repeat('b', 16),
            str_repeat('c', 16),
            str_repeat('d', 16),
            str_repeat('e', 6),
        ]);

        $this->loginAs('beau-sse@example.com', 'hunter2hunter2');
        $this->client->followRedirect();

        $this->client->request('GET', '/chat');
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/chat', $location);
        $crawler = $this->client->followRedirect();
        if (302 === $this->client->getResponse()->getStatusCode()) {
            $crawler = $this->client->followRedirect();
        }
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Use the form's data-stream-action to grab CSRF + thread id, then POST to it.
        $form = $crawler->selectButton('Send')->form();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-stream-action=', $html, 'chat form must expose data-stream-action');
        preg_match('/data-stream-action="([^"]+)"/', $html, $matches);
        $streamAction = $matches[1] ?? '';
        self::assertNotEmpty($streamAction);

        $this->client->request(
            'POST',
            $streamAction,
            [
                '_csrf_token' => $form['_csrf_token']->getValue(),
                'text' => 'hello stream',
            ],
            [],
            ['HTTP_ACCEPT' => 'text/event-stream'],
        );

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        self::assertInstanceOf(StreamedResponse::class, $response);

        // BrowserKit's HttpKernelBrowser::filterResponse() drives sendContent()
        // through a chunk-capturing ob_start callback into the BrowserKit
        // (internal) DomResponse — that's where the SSE body lives.
        $body = (string) $this->client->getInternalResponse()->getContent();
        self::assertNotSame('', $body, 'StreamedResponse must have produced SSE output');

        // Parse out the SSE events.
        $events = [];
        foreach (explode("\n\n", trim($body)) as $chunk) {
            $chunk = trim($chunk);
            if ('' === $chunk) {
                continue;
            }
            foreach (explode("\n", $chunk) as $line) {
                if (str_starts_with($line, 'data: ')) {
                    $events[] = json_decode(substr($line, 6), true, flags: \JSON_THROW_ON_ERROR);
                }
            }
        }

        self::assertNotEmpty($events, 'expected at least one SSE event');
        $deltas = array_values(array_filter($events, static fn ($e) => 'delta' === ($e['type'] ?? '')));
        $dones = array_values(array_filter($events, static fn ($e) => 'done' === ($e['type'] ?? '')));

        self::assertGreaterThanOrEqual(2, count($deltas), 'expected coalesced deltas + a final flush');
        self::assertCount(1, $dones, 'expected exactly one terminal done event');

        // Cumulative monotonic growth.
        $prev = '';
        foreach ($deltas as $idx => $d) {
            self::assertGreaterThanOrEqual(\strlen($prev), \strlen($d['text']), "delta {$idx} must not shrink");
            self::assertSame($prev, substr($d['text'], 0, \strlen($prev)), "delta {$idx} must extend prior");
            $prev = $d['text'];
        }

        $expectedText = str_repeat('a', 16).str_repeat('b', 16).str_repeat('c', 16).str_repeat('d', 16).str_repeat('e', 6);
        self::assertSame($expectedText, $prev, 'last delta must equal final text');
        self::assertSame($expectedText, $dones[0]['text']);

        // Platform was invoked with stream: true exactly once.
        $calls = $this->platform->calls();
        self::assertCount(1, $calls);
        self::assertTrue($calls[0]['options']['stream'] ?? false);
    }

    public function testStreamEndpointRejectsAnonymous(): void
    {
        // Anonymous POST: must NOT succeed with 200.
        $this->client->request('POST', '/chat/00000000-0000-0000-0000-000000000000/messages/stream');
        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status, "anonymous POST must not stream a turn (got {$status})");
        self::assertGreaterThanOrEqual(300, $status, "expected redirect or denial, got {$status}");
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

final class TransientStreamCtrlUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
