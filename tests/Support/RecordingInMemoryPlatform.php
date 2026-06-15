<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;

/**
 * Test double around symfony/ai's {@see InMemoryPlatform}. Returns a canned
 * string per call AND records each invocation (model id + input + options)
 * for assertions in the chat-loop test.
 *
 * Configured in config/services_test.yaml so the production
 * {@see \App\Ai\ModelProfile\ConfigModelProfileResolver} resolves
 * chat.frontier to this platform in the test env — no live API key needed.
 *
 * Streaming support (Phase 0.4): when the caller passes `stream: true` in
 * options, the platform returns a {@see StreamResult} of {@see TextDelta}s.
 * Chunks default to one character per delta (so a streaming test exercises
 * the loop's coalescing logic), or the test can pin an explicit chunk list
 * via {@see self::setNextReplyChunks()}.
 *
 * Mutable in-memory state is reset per test via reset(). Test isolation
 * (random execution order) is the suite-wide convention; the resolver is
 * shared, but state is keyed to each call.
 */
final class RecordingInMemoryPlatform implements PlatformInterface
{
    /** @var list<array{model: string, input: mixed, options: array<string, mixed>}> */
    private static array $calls = [];

    private static string $nextReply = 'echo: ok';

    /** @var list<string>|null */
    private static ?array $nextReplyChunks = null;

    private readonly InMemoryPlatform $inner;

    public function __construct()
    {
        $this->inner = new InMemoryPlatform(static function (Model $model, array|string|object $input, array $options): TextResult|StreamResult {
            self::$calls[] = [
                'model' => $model->getName(),
                'input' => $input,
                'options' => $options,
            ];

            $chunks = self::$nextReplyChunks ?? self::splitForStream(self::$nextReply);

            if ($options['stream'] ?? false) {
                return new StreamResult(self::generateChunks($chunks));
            }

            return new TextResult(implode('', $chunks));
        });
    }

    public function setNextReply(string $reply): void
    {
        self::$nextReply = $reply;
        self::$nextReplyChunks = null;
    }

    /**
     * Pin an explicit list of streamed chunks for the next invocation. Useful
     * for asserting coalescing thresholds with deterministic boundaries.
     *
     * @param list<string> $chunks
     */
    public function setNextReplyChunks(array $chunks): void
    {
        self::$nextReplyChunks = $chunks;
        self::$nextReply = implode('', $chunks);
    }

    /**
     * @return list<array{model: string, input: mixed, options: array<string, mixed>}>
     */
    public function calls(): array
    {
        return self::$calls;
    }

    public function reset(): void
    {
        self::$calls = [];
        self::$nextReply = 'echo: ok';
        self::$nextReplyChunks = null;
    }

    public function invoke(string $model, array|string|object $input, array $options = []): DeferredResult
    {
        return $this->inner->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }

    /**
     * @return list<string>
     */
    private static function splitForStream(string $text): array
    {
        if ('' === $text) {
            return [''];
        }

        return mb_str_split($text);
    }

    /**
     * @param list<string> $chunks
     *
     * @return \Generator<TextDelta>
     */
    private static function generateChunks(array $chunks): \Generator
    {
        foreach ($chunks as $chunk) {
            yield new TextDelta($chunk);
        }
    }
}
