<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessagePartRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * `message_parts` projection. v0 carries one `text` part per message;
 * `position` is reserved for streaming layout (multi-part assistant turns)
 * once 0.3 lands. `(message_id, position)` is unique.
 */
#[ORM\Entity(repositoryClass: MessagePartRepository::class)]
#[ORM\Table(name: 'message_parts')]
#[ORM\UniqueConstraint(name: 'uniq_message_parts_message_position', columns: ['message_id', 'position'])]
class MessagePart
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'message_id', type: 'uuid')]
    private Uuid $messageId;

    #[ORM\Column(name: 'thread_id', type: 'uuid')]
    private Uuid $threadId;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(type: 'integer')]
    private int $position;

    #[ORM\Column(type: 'string', length: 32)]
    private string $kind;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $id,
        Uuid $messageId,
        Uuid $threadId,
        Uuid $tenantId,
        int $position,
        string $kind,
        string $content,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->messageId = $messageId;
        $this->threadId = $threadId;
        $this->tenantId = $tenantId;
        $this->position = $position;
        $this->kind = $kind;
        $this->content = $content;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMessageId(): Uuid
    {
        return $this->messageId;
    }

    public function getThreadId(): Uuid
    {
        return $this->threadId;
    }

    public function getTenantId(): Uuid
    {
        return $this->tenantId;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function replaceContent(string $content): void
    {
        $this->content = $content;
    }
}
