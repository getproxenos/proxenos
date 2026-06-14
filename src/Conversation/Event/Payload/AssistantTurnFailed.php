<?php

declare(strict_types=1);

namespace App\Conversation\Event\Payload;

use App\Enum\ConversationEventType;
use Symfony\Component\Uid\Uuid;

/**
 * Payload for `assistant_turn_failed` (ADR-025). The envelope's `turn_id`
 * identifies the failed turn; the projection rows for that turn (Turn row
 * and any assistant Message that began streaming) move to `failed`.
 *
 * `messageId` is nullable because failure may strike before any
 * `assistant_content_delta` was appended — i.e. before an assistant
 * Message row exists. When present, the fold marks that specific message
 * `failed`; when absent, no message-level state change is needed.
 *
 * `finishReason` carries a short, sanitized failure category — never a raw
 * exception message and never a stack trace. v0 keeps the producer
 * responsible for that sanitization (see `ChatRespondLoop`).
 */
final readonly class AssistantTurnFailed implements EventPayload
{
    public function __construct(
        public ?Uuid $messageId,
        public string $finishReason,
        public string $errorSummary = '',
    ) {
        if ('' === $finishReason) {
            throw new \InvalidArgumentException('assistant_turn_failed.finish_reason must be non-empty.');
        }
    }

    public function type(): ConversationEventType
    {
        return ConversationEventType::ASSISTANT_TURN_FAILED;
    }

    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId?->toRfc4122(),
            'finish_reason' => $this->finishReason,
            'error_summary' => $this->errorSummary,
        ];
    }
}
