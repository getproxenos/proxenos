<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Operation-compatible result envelope for {@see ChatRespondLoop}. Mirrors
 * ADR-014's operation `output` + `usage` blocks. `usage` is nullable because
 * not every Platform bridge exposes a token-usage extractor (the generic
 * OpenAI-compatible bridge is sparse here; symfony/ai-anthropic-platform
 * surfaces them via TokenUsageExtractor).
 */
final readonly class ChatRespondResult
{
    public function __construct(
        public Uuid $threadId,
        public Uuid $turnId,
        public Uuid $assistantMessageId,
        public string $assistantText,
        public ?TokenUsageInterface $usage = null,
    ) {
    }
}
