<?php

declare(strict_types=1);

namespace App\Conversation\Event\Payload;

use App\Enum\ConversationEventType;
use Symfony\Component\Uid\Uuid;

/**
 * Payload for `assistant_turn_cancelled` (step-03 chunk D7, decision 4). Shape
 * mirrors {@see AssistantTurnFailed} exactly: the envelope's `turn_id`
 * identifies the cancelled turn; the projection rows for that turn (Turn row
 * and any assistant Message that began streaming) move to `cancelled`.
 *
 * Unlike failure, cancellation is a cooperative user-initiated stop, not an
 * error — `ChatRespondLoop` appends this from a NORMAL return path, never from
 * the `assistant_turn_failed` catch.
 *
 * `messageId` is nullable for the same reason as failure: the cooperative stop
 * can in principle trip before any `assistant_content_delta` landed — i.e.
 * before an assistant Message row exists. When present, the fold marks that
 * specific message `cancelled`; when absent, no message-level state change is
 * needed. `finishReason` defaults to `cancelled`; `errorSummary` is normally
 * empty (cancellation carries no error to summarize).
 */
final readonly class AssistantTurnCancelled implements EventPayload
{
    public function __construct(
        public ?Uuid $messageId,
        public string $finishReason = 'cancelled',
        public string $errorSummary = '',
    ) {
        if ('' === $finishReason) {
            throw new \InvalidArgumentException('assistant_turn_cancelled.finish_reason must be non-empty.');
        }
    }

    public function type(): ConversationEventType
    {
        return ConversationEventType::ASSISTANT_TURN_CANCELLED;
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
