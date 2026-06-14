<?php

declare(strict_types=1);

namespace App\Conversation\Event;

use App\Conversation\Event\Payload\EventPayload;
use App\Enum\ActorType;
use Symfony\Component\Uid\Uuid;

/**
 * Caller-supplied portion of a `conversation_events` row, before `EventAppender`
 * stamps `sequence` and `occurred_at`. Carries the envelope columns the
 * payload doesn't repeat (tenant/thread/turn ids, actor identity, optional
 * correlation/causation/idempotency_key).
 *
 * Intentionally a value object: appenders never mutate it. The branch_id
 * column ships nullable + unused per design-notes §2; we expose the parameter
 * here purely so the type is honest, but every Phase 0.2 caller passes null.
 */
final readonly class EventEnvelope
{
    public function __construct(
        public Uuid $tenantId,
        public Uuid $threadId,
        public ?Uuid $turnId,
        public ActorType $actorType,
        public ?string $actorId,
        public EventPayload $payload,
        public ?Uuid $correlationId = null,
        public ?Uuid $causationId = null,
        public ?string $idempotencyKey = null,
        public ?Uuid $branchId = null,
        public int $version = 1,
    ) {
    }
}
