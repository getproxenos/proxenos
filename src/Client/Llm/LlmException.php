<?php

declare(strict_types=1);

namespace App\Client\Llm;

/**
 * Typed failure for any model call through {@see LlmClient} — not configured,
 * request failed, or response missing a completion. Callers catch this to
 * degrade gracefully.
 */
final class LlmException extends \RuntimeException
{
    public function __construct(string $message = '', ?int $code = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code ?? 0, $previous);
    }
}
