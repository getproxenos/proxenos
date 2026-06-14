<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ActorType;
use App\Enum\ConversationEventType;
use App\Repository\ConversationEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Canonical event-log row (design-notes/event-sourced-conversations.md §2,
 * ADR-004). One row per state change; projection tables (`threads`, `turns`,
 * `messages`, `message_parts`) are folded from these.
 *
 * Schema decisions specific to Phase 0.2 are recorded in ADR-022:
 * - The doc's `workspace_id` is implemented as `tenant_id` (ADR-021 alignment).
 * - `branch_id` ships nullable + unused; reserved for branch/retry semantics.
 * - Identifiers are stored as raw `uuid` columns rather than ManyToOne
 *   relations because the write path is append-mostly; the FK to `tenants` is
 *   enforced at the DB level by the migration.
 * - `(thread_id, sequence)` is unique; `EventAppender` assigns sequence as
 *   `MAX+1` within the append transaction.
 */
#[ORM\Entity(repositoryClass: ConversationEventRepository::class)]
#[ORM\Table(name: 'conversation_events')]
#[ORM\UniqueConstraint(name: 'uniq_conversation_events_thread_sequence', columns: ['thread_id', 'sequence'])]
#[ORM\Index(name: 'idx_conversation_events_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_conversation_events_turn', columns: ['turn_id'])]
final class ConversationEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(name: 'thread_id', type: 'uuid')]
    private Uuid $threadId;

    #[ORM\Column(name: 'branch_id', type: 'uuid', nullable: true)]
    private ?Uuid $branchId;

    #[ORM\Column(name: 'turn_id', type: 'uuid', nullable: true)]
    private ?Uuid $turnId;

    #[ORM\Column(name: 'sequence', type: 'bigint')]
    private int $sequence;

    #[ORM\Column(type: 'string', length: 64, enumType: ConversationEventType::class)]
    private ConversationEventType $type;

    #[ORM\Column(type: 'integer')]
    private int $version;

    #[ORM\Column(name: 'actor_type', type: 'string', length: 32, enumType: ActorType::class)]
    private ActorType $actorType;

    #[ORM\Column(name: 'actor_id', type: 'string', length: 255, nullable: true)]
    private ?string $actorId;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(name: 'correlation_id', type: 'uuid', nullable: true)]
    private ?Uuid $correlationId;

    #[ORM\Column(name: 'causation_id', type: 'uuid', nullable: true)]
    private ?Uuid $causationId;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 255, nullable: true)]
    private ?string $idempotencyKey;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(name: 'redaction_state', type: 'string', length: 16)]
    private string $redactionState;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        Uuid $threadId,
        ?Uuid $turnId,
        int $sequence,
        ConversationEventType $type,
        ActorType $actorType,
        ?string $actorId,
        \DateTimeImmutable $occurredAt,
        array $payload,
        int $version = 1,
        ?Uuid $correlationId = null,
        ?Uuid $causationId = null,
        ?string $idempotencyKey = null,
        ?Uuid $branchId = null,
        string $redactionState = 'normal',
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->threadId = $threadId;
        $this->turnId = $turnId;
        $this->sequence = $sequence;
        $this->type = $type;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->occurredAt = $occurredAt;
        $this->payload = $payload;
        $this->version = $version;
        $this->correlationId = $correlationId;
        $this->causationId = $causationId;
        $this->idempotencyKey = $idempotencyKey;
        $this->branchId = $branchId;
        $this->redactionState = $redactionState;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getThreadId(): Uuid
    {
        return $this->threadId;
    }

    public function getBranchId(): ?Uuid
    {
        return $this->branchId;
    }

    public function getTurnId(): ?Uuid
    {
        return $this->turnId;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getType(): ConversationEventType
    {
        return $this->type;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getActorType(): ActorType
    {
        return $this->actorType;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getCorrelationId(): ?Uuid
    {
        return $this->correlationId;
    }

    public function getCausationId(): ?Uuid
    {
        return $this->causationId;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getRedactionState(): string
    {
        return $this->redactionState;
    }
}
