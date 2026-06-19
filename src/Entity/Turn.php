<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TurnStatus;
use App\Repository\TurnRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * `turns` projection. One row per assistant generation (`turn_id` from the
 * event envelope). Lifecycle in 0.2: `pending` (created) → `streaming` (first
 * `assistant_content_delta`) → `completed` (`assistant_turn_completed`).
 * `failed` / `cancelled` reserve their slot for 0.3+.
 */
#[ORM\Entity(repositoryClass: TurnRepository::class)]
#[ORM\Table(name: 'turns')]
#[ORM\Index(name: 'idx_turns_thread', columns: ['thread_id'])]
class Turn
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'thread_id', type: 'uuid')]
    private Uuid $threadId;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'string', length: 16, enumType: TurnStatus::class)]
    private TurnStatus $status;

    #[ORM\Column(name: 'last_sequence', type: 'bigint')]
    private int $lastSequence;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt;

    public function __construct(
        Uuid $id,
        Uuid $threadId,
        Uuid $tenantId,
        int $sequence,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->threadId = $threadId;
        $this->tenantId = $tenantId;
        $this->status = TurnStatus::PENDING;
        $this->lastSequence = $sequence;
        $this->createdAt = $createdAt;
        $this->completedAt = null;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getThreadId(): Uuid
    {
        return $this->threadId;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getStatus(): TurnStatus
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

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function markStreaming(int $sequence): void
    {
        if (TurnStatus::PENDING === $this->status) {
            $this->status = TurnStatus::STREAMING;
        }
        $this->bumpSequence($sequence);
    }

    public function markCompleted(int $sequence, \DateTimeImmutable $completedAt): void
    {
        $this->status = TurnStatus::COMPLETED;
        $this->completedAt = $completedAt;
        $this->bumpSequence($sequence);
    }

    public function markFailed(int $sequence, \DateTimeImmutable $failedAt): void
    {
        $this->status = TurnStatus::FAILED;
        $this->completedAt = $failedAt;
        $this->bumpSequence($sequence);
    }

    public function markCancelled(int $sequence, \DateTimeImmutable $cancelledAt): void
    {
        $this->status = TurnStatus::CANCELLED;
        $this->completedAt = $cancelledAt;
        $this->bumpSequence($sequence);
    }

    private function bumpSequence(int $sequence): void
    {
        if ($sequence > $this->lastSequence) {
            $this->lastSequence = $sequence;
        }
    }
}
