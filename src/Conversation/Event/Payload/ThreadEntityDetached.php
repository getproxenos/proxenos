<?php

declare(strict_types=1);

namespace App\Conversation\Event\Payload;

use App\Enum\ConversationEventType;

/**
 * Payload for `thread_entity_detached` (step-02 decision 6). Just the identity
 * triple — the host compares references by byte-equality of
 * `provider`/`type`/`id` (ADR-013a; `id` is opaque). The fold DELETEs the
 * matching `thread_attachments` row; deleting an absent row is a no-op so a
 * full replay stays idempotent.
 */
final readonly class ThreadEntityDetached implements EventPayload
{
    public function __construct(
        public string $provider,
        public string $type,
        public string $id,
    ) {
        if ('' === $provider) {
            throw new \InvalidArgumentException('thread_entity_detached.provider must be non-empty.');
        }
        if ('' === $type) {
            throw new \InvalidArgumentException('thread_entity_detached.type must be non-empty.');
        }
        if ('' === $id) {
            throw new \InvalidArgumentException('thread_entity_detached.id must be non-empty.');
        }
    }

    public function type(): ConversationEventType
    {
        return ConversationEventType::THREAD_ENTITY_DETACHED;
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'type' => $this->type,
            'id' => $this->id,
        ];
    }
}
