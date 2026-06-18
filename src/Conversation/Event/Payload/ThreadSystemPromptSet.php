<?php

declare(strict_types=1);

namespace App\Conversation\Event\Payload;

use App\Enum\ConversationEventType;

/**
 * Payload for `thread_system_prompt_set` (step-03 chunk D9, decision 5). Carries
 * the per-thread system-prompt override; the fold writes it onto the `threads`
 * projection via `Thread::setSystemPrompt()`. A `null` value CLEARS the override
 * (the effective prompt then falls back to the user's global default — see
 * {@see \App\Ai\Chat\SystemPromptResolver}). Named explicitly (not a generic
 * `thread_metadata_changed`) so the event vocabulary stays self-describing
 * (decision 3), mirroring {@see ThreadRenamed} / {@see ThreadArchived}.
 *
 * Unlike `thread_renamed`, a blank/empty string is NOT rejected here — clearing
 * the override is a legitimate write, normalized to `null` at the controller
 * boundary so the stored value is either a non-empty prompt or `null`.
 */
final readonly class ThreadSystemPromptSet implements EventPayload
{
    public function __construct(
        public ?string $systemPrompt,
    ) {
    }

    public function type(): ConversationEventType
    {
        return ConversationEventType::THREAD_SYSTEM_PROMPT_SET;
    }

    public function toArray(): array
    {
        return ['system_prompt' => $this->systemPrompt];
    }
}
