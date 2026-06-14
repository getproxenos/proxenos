<?php

declare(strict_types=1);

namespace App\Conversation\Event\Payload;

use App\Enum\ConversationEventType;

/**
 * Payload for `assistant_turn_created`. Empty — the `turn_id` lives in the
 * envelope and that is all the projection needs to instantiate a `pending`
 * row. Reserved for future fields (e.g. model id) once 0.3 lands.
 */
final readonly class AssistantTurnCreated implements EventPayload
{
    public function type(): ConversationEventType
    {
        return ConversationEventType::ASSISTANT_TURN_CREATED;
    }

    public function toArray(): array
    {
        return [];
    }
}
