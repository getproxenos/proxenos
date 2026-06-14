<?php

declare(strict_types=1);

namespace App\Conversation\Event\Payload;

use App\Enum\ConversationEventType;
use Symfony\Component\Uid\Uuid;

/**
 * Payload for `assistant_content_delta`. v0 emits one delta per turn carrying
 * the whole text; fold semantics is "replace the part at (message_id,
 * part_index)". Once 0.3 lands real streaming, ADR-022 will revisit whether
 * deltas carry the cumulative text (replace) or the marginal text (append).
 */
final readonly class AssistantContentDelta implements EventPayload
{
    public function __construct(
        public Uuid $messageId,
        public int $partIndex,
        public string $text,
    ) {
        if ($partIndex < 0) {
            throw new \InvalidArgumentException('assistant_content_delta.part_index must be >= 0.');
        }
    }

    public function type(): ConversationEventType
    {
        return ConversationEventType::ASSISTANT_CONTENT_DELTA;
    }

    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId->toRfc4122(),
            'part_index' => $this->partIndex,
            'text' => $this->text,
        ];
    }
}
