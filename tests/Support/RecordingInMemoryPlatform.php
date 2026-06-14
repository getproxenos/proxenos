<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
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
 * Mutable in-memory state is reset per test via reset(). Test isolation
 * (random execution order) is the suite-wide convention; the resolver is
 * shared, but state is keyed to each call.
 */
final class RecordingInMemoryPlatform implements PlatformInterface
{
    /** @var list<array{model: string, input: mixed, options: array<string, mixed>}> */
    private static array $calls = [];

    private static string $nextReply = 'echo: ok';

    private readonly InMemoryPlatform $inner;

    public function __construct()
    {
        $this->inner = new InMemoryPlatform(static function (Model $model, array|string|object $input, array $options): string {
            self::$calls[] = [
                'model' => $model->getName(),
                'input' => $input,
                'options' => $options,
            ];

            return self::$nextReply;
        });
    }

    public function setNextReply(string $reply): void
    {
        self::$nextReply = $reply;
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
    }

    public function invoke(string $model, array|string|object $input, array $options = []): DeferredResult
    {
        return $this->inner->invoke($model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }
}
