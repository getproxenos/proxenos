<?php

declare(strict_types=1);

namespace App\Conversation\Event\Payload;

use App\Enum\ConversationEventType;
use App\TypedEntity\Reference;

/**
 * Payload for `thread_entity_attached` (step-02 decision 6). Carries the full
 * `Reference` envelope so the `thread_attachments` projection can reconstruct
 * the reference byte-faithfully (opaque id, expansion, snapshot, …) when
 * `ThreadAttachmentService::listForThread()` reads it back.
 *
 * `attached_at` is optional: when the appender doesn't supply it the fold falls
 * back to the event's `occurred_at` for the projection's `attached_at` column.
 * The envelope's identity triple (`provider`/`type`/`id`) is the dedup key.
 */
final readonly class ThreadEntityAttached implements EventPayload
{
    public function __construct(
        public Reference $reference,
        public ?\DateTimeImmutable $attachedAt = null,
    ) {
    }

    public function type(): ConversationEventType
    {
        return ConversationEventType::THREAD_ENTITY_ATTACHED;
    }

    public function toArray(): array
    {
        $out = ['reference' => $this->reference->toArray()];
        if (null !== $this->attachedAt) {
            $out['attached_at'] = $this->attachedAt->format(\DateTimeInterface::ATOM);
        }

        return $out;
    }
}
