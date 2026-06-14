<?php

declare(strict_types=1);

namespace App\Conversation\Event\Payload;

use App\Enum\ConversationEventType;
use Symfony\Component\Uid\Uuid;

/**
 * Payload for `assistant_turn_completed`. Carries the completed
 * `message_id` and a finish reason; the envelope's `turn_id` identifies the
 * turn whose state moves to `completed`.
 */
final readonly class AssistantTurnCompleted implements EventPayload
{
    public function __construct(
        public Uuid $messageId,
        public string $finishReason = 'stop',
    ) {
        if ('' === $finishReason) {
            throw new \InvalidArgumentException('assistant_turn_completed.finish_reason must be non-empty.');
        }
    }

    public function type(): ConversationEventType
    {
        return ConversationEventType::ASSISTANT_TURN_COMPLETED;
    }

    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId->toRfc4122(),
            'finish_reason' => $this->finishReason,
        ];
    }
}
