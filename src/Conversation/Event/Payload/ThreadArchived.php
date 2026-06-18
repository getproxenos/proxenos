<?php

declare(strict_types=1);

namespace App\Conversation\Event\Payload;

use App\Enum\ConversationEventType;

/**
 * Payload for `thread_archived` (step-03 chunk D2, decision 10). Empty — the
 * thread id lives on the envelope and archiving is a soft, event-sourced hide
 * (the fold sets `status = 'archived'`), never a delete. Replaying the log
 * still reconstructs the full history; the projection just stops listing it.
 */
final readonly class ThreadArchived implements EventPayload
{
    public function type(): ConversationEventType
    {
        return ConversationEventType::THREAD_ARCHIVED;
    }

    public function toArray(): array
    {
        return [];
    }
}
