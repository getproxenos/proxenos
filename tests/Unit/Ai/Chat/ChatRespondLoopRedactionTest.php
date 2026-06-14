<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Chat;

use App\Ai\Chat\ChatRespondLoop;
use PHPUnit\Framework\TestCase;

/**
 * `ChatRespondLoop::summarizeError()` produces the `error_summary` field
 * persisted in every `assistant_turn_failed` event. That payload is durable
 * forever and ends up in projection rebuilds and audit exports, so the
 * redaction rules ADR-025 promises have to actually fire — not just be
 * documented in a comment.
 *
 * `summarizeError()` is private static; this test invokes it via reflection
 * because the method has no other public surface and exposing one purely
 * for testability would invert the leverage. The contract this test pins
 * is the redacted observable string, not the method's signature.
 */
final class ChatRespondLoopRedactionTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function redactionCases(): array
    {
        return [
            'openai-style sk- key' => [
                '401 Unauthorized: invalid key sk-live-abcDEF123456789012345 for /v1/chat',
                ['sk-[redacted]'],
                ['sk-live-abcDEF123456789012345'],
            ],
            'anthropic-style sk- key' => [
                'auth failed for sk-ant-api03-XYZ987abc1234567890',
                ['sk-[redacted]'],
                ['sk-ant-api03-XYZ987abc1234567890'],
            ],
            'full URL with query' => [
                'POST https://api.openai.com/v1/chat/completions?token=qux failed: 500',
                ['[redacted-url]'],
                ['https://api.openai.com/v1/chat/completions', '?token=qux'],
            ],
            'bare URL no query' => [
                'POST https://api.openai.com/v1/chat/completions returned 502',
                ['[redacted-url]'],
                ['api.openai.com', 'v1/chat/completions'],
            ],
            'bearer header echoed' => [
                'Request failed: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig was rejected',
                ['Bearer [redacted]'],
                ['eyJhbGciOiJIUzI1NiJ9.payload.sig'],
            ],
            'long base64 token without a known prefix' => [
                'Got error code abcDEF0123456789abcDEF0123456789== from upstream',
                ['[redacted]'],
                ['abcDEF0123456789abcDEF0123456789'],
            ],
            'short hex / model id stays intact' => [
                'Unknown model id: claude-sonnet-4-6 for tenant 019ec729-48f8-7a28-88c3',
                ['claude-sonnet-4-6'],
                ['[redacted]'],
            ],
        ];
    }

    /**
     * @param list<string> $mustContain
     * @param list<string> $mustNotContain
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('redactionCases')]
    public function testSummarizeErrorRedactsSecrets(string $message, array $mustContain, array $mustNotContain): void
    {
        $summary = self::invokeSummarize(new \RuntimeException($message));

        // The class prefix should always be there as the load-bearing signal.
        self::assertStringStartsWith('RuntimeException', $summary);

        foreach ($mustContain as $needle) {
            self::assertStringContainsString($needle, $summary, "expected redaction token '{$needle}' in: {$summary}");
        }
        foreach ($mustNotContain as $secret) {
            self::assertStringNotContainsString($secret, $summary, "secret '{$secret}' must not survive in: {$summary}");
        }

        // Hard length cap from the docblock contract.
        self::assertLessThanOrEqual(140 + \strlen('RuntimeException: '), \strlen($summary));
    }

    public function testEmptyMessageFallsBackToClassOnly(): void
    {
        $summary = self::invokeSummarize(new \RuntimeException(''));
        self::assertSame('RuntimeException', $summary);
    }

    public function testWhitespaceCollapsedAfterRedaction(): void
    {
        $summary = self::invokeSummarize(new \RuntimeException("multi\n   line   message   here"));
        self::assertSame('RuntimeException: multi line message here', $summary);
    }

    private static function invokeSummarize(\Throwable $e): string
    {
        $method = new \ReflectionMethod(ChatRespondLoop::class, 'summarizeError');

        return (string) $method->invoke(null, $e);
    }
}
