<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\ConversationEvent;

/**
 * Normalizes a stored {@see ConversationEvent} to the wire envelope shared
 * by the replay endpoint and the Mercure push transport (handoff §1 / §2,
 * ADR-026, `design-notes/streaming-runtime-notes.md` §5).
 *
 * The contract: live + replay frames MUST be byte-identical for the same
 * (thread_id, sequence). The SPA reducer dedupes on `id` / `sequence` and
 * folds through one union — diverging shapes between the two transports
 * would force the reducer to branch and would re-introduce the reconcile
 * complexity ADR-024 specifically pushed onto the host.
 */
final class ConversationEventEnvelope
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(ConversationEvent $event): array
    {
        return [
            'id' => $event->getId()->toRfc4122(),
            'sequence' => $event->getSequence(),
            'thread_id' => $event->getThreadId()->toRfc4122(),
            'turn_id' => $event->getTurnId()?->toRfc4122(),
            'type' => $event->getType()->value,
            'version' => $event->getVersion(),
            'actor_type' => $event->getActorType()->value,
            'actor_id' => $event->getActorId(),
            'occurred_at' => $event->getOccurredAt()->format(\DateTimeInterface::RFC3339),
            'payload' => $event->getPayload(),
        ];
    }

    /**
     * Per-thread Mercure topic URI. Constructed as a coordinate, not a
     * URL — the SPA subscribes by this exact string and the hub treats it
     * opaquely.
     */
    public function topicForThread(string $threadId): string
    {
        return \sprintf('/threads/%s/events', $threadId);
    }
}
