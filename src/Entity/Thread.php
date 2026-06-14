<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ThreadRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * `threads` projection from the canonical event log. Folded from
 * `conversation_events` by `ProjectionFolder`; rebuildable by
 * `app:projections:rebuild`. `last_sequence` is the cursor of the highest
 * event already folded into this thread's projection rows.
 */
#[ORM\Entity(repositoryClass: ThreadRepository::class)]
#[ORM\Table(name: 'threads')]
#[ORM\Index(name: 'idx_threads_tenant', columns: ['tenant_id'])]
class Thread
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(name: 'created_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $createdByUserId;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $title;

    #[ORM\Column(type: 'string', length: 16)]
    private string $status;

    #[ORM\Column(name: 'last_sequence', type: 'bigint')]
    private int $lastSequence;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        ?Uuid $createdByUserId,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->createdByUserId = $createdByUserId;
        $this->title = null;
        $this->status = 'active';
        $this->lastSequence = 0;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getCreatedByUserId(): ?Uuid
    {
        return $this->createdByUserId;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getLastSequence(): int
    {
        return $this->lastSequence;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Title is populated later (compaction summary; design-notes §4 "conversation
     * entity projection: title, summary, …"); Phase 0.2 only reserves the
     * column. Surface kept here so 0.3+ can fold thread_compacted without an
     * entity migration.
     */
    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function recordEvent(int $sequence, \DateTimeImmutable $occurredAt): void
    {
        if ($sequence > $this->lastSequence) {
            $this->lastSequence = $sequence;
        }
        if ($occurredAt > $this->updatedAt) {
            $this->updatedAt = $occurredAt;
        }
    }
}
