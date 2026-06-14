<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Conversation\Event\EventEnvelope;
use App\Entity\ConversationEvent;
use App\Repository\ConversationEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Write path for the canonical event log (ADR-004 + ADR-022). Wraps every
 * append in a transaction that:
 *   1. computes the next sequence as MAX(sequence)+1 for the thread,
 *   2. persists a `ConversationEvent` row,
 *   3. inline-folds the event into projections via `ProjectionFolder`.
 *
 * Sequence safety net: the `(thread_id, sequence)` UNIQUE INDEX rejects any
 * race. Single-user v0 doesn't engineer a retry loop or advisory lock —
 * ADR-022 flags both for 0.3+ if write concurrency becomes real.
 */
final class EventAppender
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationEventRepository $events,
        private readonly ProjectionFolder $folder,
        private readonly ClockInterface $clock,
    ) {
    }

    public function append(EventEnvelope $envelope): ConversationEvent
    {
        /** @var ConversationEvent $persisted */
        $persisted = $this->em->wrapInTransaction(function () use ($envelope): ConversationEvent {
            $sequence = $this->events->maxSequenceForThread($envelope->threadId) + 1;
            $payload = $envelope->payload;

            $event = new ConversationEvent(
                Uuid::v7(),
                $envelope->tenantId,
                $envelope->threadId,
                $envelope->turnId,
                $sequence,
                $payload->type(),
                $envelope->actorType,
                $envelope->actorId,
                $this->clock->now(),
                $payload->toArray(),
                $envelope->version,
                $envelope->correlationId,
                $envelope->causationId,
                $envelope->idempotencyKey,
                $envelope->branchId,
            );

            $this->em->persist($event);
            $this->folder->apply($event);

            return $event;
        });

        return $persisted;
    }
}
