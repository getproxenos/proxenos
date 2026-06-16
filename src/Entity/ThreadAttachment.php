<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ThreadAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * `thread_attachments` projection from the canonical event log (step-02
 * decision 6). Folded from `thread_entity_attached` / `thread_entity_detached`
 * by `ProjectionFolder`; rebuildable by `app:projections:rebuild`.
 *
 * Composite key is the thread plus the reference's identity triple
 * (`provider` + `type` + `entity_id`). `entity_id` is the OPAQUE reference id
 * (ADR-013a) — kept as a plain varchar, NOT a uuid, because non-host providers
 * won't mint uuids. The full `Reference` envelope is stored in `reference` so
 * `ThreadAttachmentService::listForThread()` reconstructs it byte-faithfully.
 *
 * `last_sequence` mirrors the cursor every projection row in this codebase
 * carries: a fold whose event has already been applied
 * (`event.sequence <= last_sequence`) is skipped, which keeps replay idempotent.
 */
#[ORM\Entity(repositoryClass: ThreadAttachmentRepository::class)]
#[ORM\Table(name: 'thread_attachments')]
#[ORM\Index(name: 'idx_thread_attachments_tenant_thread', columns: ['tenant_id', 'thread_id'])]
class ThreadAttachment
{
    #[ORM\Id]
    #[ORM\Column(name: 'thread_id', type: 'uuid')]
    private Uuid $threadId;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $provider;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 128)]
    private string $type;

    #[ORM\Id]
    #[ORM\Column(name: 'entity_id', type: 'string', length: 255)]
    private string $entityId;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $reference;

    #[ORM\Column(name: 'attached_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $attachedAt;

    #[ORM\Column(name: 'last_sequence', type: 'bigint')]
    private int $lastSequence;

    /**
     * @param array<string, mixed> $reference full ADR-013a reference envelope
     */
    public function __construct(
        Uuid $threadId,
        Uuid $tenantId,
        string $provider,
        string $type,
        string $entityId,
        array $reference,
        \DateTimeImmutable $attachedAt,
        int $lastSequence,
    ) {
        $this->threadId = $threadId;
        $this->tenantId = $tenantId;
        $this->provider = $provider;
        $this->type = $type;
        $this->entityId = $entityId;
        $this->reference = $reference;
        $this->attachedAt = $attachedAt;
        $this->lastSequence = $lastSequence;
    }

    public function getThreadId(): Uuid
    {
        return $this->threadId;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    /** @return array<string, mixed> */
    public function getReference(): array
    {
        return $this->reference;
    }

    public function getAttachedAt(): \DateTimeImmutable
    {
        return $this->attachedAt;
    }

    public function getLastSequence(): int
    {
        return $this->lastSequence;
    }

    /**
     * Re-attach of an already-attached reference: refresh the stored envelope
     * and advance the cursor, but keep the original `attached_at` so the list
     * order stays stable.
     *
     * @param array<string, mixed> $reference
     */
    public function reattach(array $reference, int $sequence): void
    {
        $this->reference = $reference;
        if ($sequence > $this->lastSequence) {
            $this->lastSequence = $sequence;
        }
    }
}
