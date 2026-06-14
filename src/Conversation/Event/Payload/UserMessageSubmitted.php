<?php

declare(strict_types=1);

namespace App\Conversation\Event\Payload;

use App\Enum\ConversationEventType;
use Symfony\Component\Uid\Uuid;

/**
 * Payload for `user_message_submitted`. The user's submitted text plus the
 * `message_id` that the projection will create. The envelope's `actor_id`
 * carries the submitting user's uuid; `turn_id` is NULL (user messages don't
 * belong to an assistant generation).
 */
final readonly class UserMessageSubmitted implements EventPayload
{
    public function __construct(
        public Uuid $messageId,
        public string $text,
    ) {
        if ('' === $text) {
            throw new \InvalidArgumentException('user_message_submitted.text must be non-empty.');
        }
    }

    public function type(): ConversationEventType
    {
        return ConversationEventType::USER_MESSAGE_SUBMITTED;
    }

    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId->toRfc4122(),
            'text' => $this->text,
        ];
    }
}
