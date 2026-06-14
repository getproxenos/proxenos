<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MessageRole;
use App\Enum\MessageStatus;
use App\Repository\MessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * `messages` projection. User messages have `turn_id = NULL` (they are
 * attached to the thread, not to an assistant generation); assistant messages
 * carry the envelope `turn_id`. `position` orders messages within a thread,
 * derived from event arrival; `(thread_id, position)` is unique.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'messages')]
#[ORM\UniqueConstraint(name: 'uniq_messages_thread_position', columns: ['thread_id', 'position'])]
#[ORM\Index(name: 'idx_messages_thread', columns: ['thread_id'])]
#[ORM\Index(name: 'idx_messages_turn', columns: ['turn_id'])]
class Message
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'thread_id', type: 'uuid')]
    private Uuid $threadId;

    #[ORM\Column(name: 'turn_id', type: 'uuid', nullable: true)]
    private ?Uuid $turnId;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'string', length: 16, enumType: MessageRole::class)]
    private MessageRole $role;

    #[ORM\Column(type: 'string', length: 16, enumType: MessageStatus::class)]
    private MessageStatus $status;

    #[ORM\Column(type: 'integer')]
    private int $position;

    #[ORM\Column(name: 'last_sequence', type: 'bigint')]
    private int $lastSequence;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt;

    public function __construct(
        Uuid $id,
        Uuid $threadId,
        ?Uuid $turnId,
        Uuid $tenantId,
        MessageRole $role,
        MessageStatus $status,
        int $position,
        int $lastSequence,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $completedAt = null,
    ) {
        $this->id = $id;
        $this->threadId = $threadId;
        $this->turnId = $turnId;
        $this->tenantId = $tenantId;
        $this->role = $role;
        $this->status = $status;
        $this->position = $position;
        $this->lastSequence = $lastSequence;
        $this->createdAt = $createdAt;
        $this->completedAt = $completedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getThreadId(): Uuid
    {
        return $this->threadId;
    }

    public function getTurnId(): ?Uuid
    {
        return $this->turnId;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getRole(): MessageRole
    {
        return $this->role;
    }

    public function getStatus(): MessageStatus
    {
        return $this->status;
    }

    public function getPosition(): int
    {
        return $this->position;
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

    public function bumpSequence(int $sequence): void
    {
        if ($sequence > $this->lastSequence) {
            $this->lastSequence = $sequence;
        }
    }

    public function markComplete(int $sequence, \DateTimeImmutable $completedAt): void
    {
        $this->status = MessageStatus::COMPLETE;
        $this->completedAt = $completedAt;
        $this->bumpSequence($sequence);
    }
}
