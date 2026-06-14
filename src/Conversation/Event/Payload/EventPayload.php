<?php

declare(strict_types=1);

namespace App\Conversation\Event\Payload;

use App\Enum\ConversationEventType;

/**
 * Marker contract for an event payload — the validated, typed view of a
 * `conversation_events.payload` JSONB blob. Each implementation knows its
 * `ConversationEventType` and serializes to the documented JSON shape
 * (ADR-022 records the per-type contract). The payloads are deliberately
 * narrow: envelope fields (tenant_id, thread_id, turn_id, sequence,
 * occurred_at, actor_*) live on `ConversationEvent`, not in payload JSON.
 */
interface EventPayload
{
    public function type(): ConversationEventType;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
