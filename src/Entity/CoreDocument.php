<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CoreDocumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Host-stored instance of `core.document` (ADR-017 baseline). The type
 * declaration metadata (schema + presentation hints) lives in
 * `App\TypedEntity\Core\Document\CoreDocumentDeclaration`; this entity is
 * just the row. Documents are NOT event-sourced — only attach/pin to a
 * thread rides the conversation event log (step-02 decision 3).
 *
 * `tags` is a Postgres `text[]` column; `collection` is an opaque folder
 * label. Identity is a UUIDv7 minted at construction time and is opaque to
 * the host's reference layer (ADR-013a). Per-tenant scope is enforced at
 * the repository.
 */
#[ORM\Entity(repositoryClass: CoreDocumentRepository::class)]
#[ORM\Table(name: 'core_documents')]
#[ORM\Index(name: 'idx_core_documents_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_core_documents_collection', columns: ['tenant_id', 'collection'])]
class CoreDocument
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Column(name: 'created_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $createdByUserId;

    #[ORM\Column(type: 'string', length: 200)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

    /** @var list<string> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $tags;

    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $collection;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $tags
     */
    public function __construct(
        Uuid $id,
        Uuid $tenantId,
        ?Uuid $createdByUserId,
        string $title,
        string $body,
        array $tags,
        ?string $collection,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->createdByUserId = $createdByUserId;
        $this->title = self::normalizeTitle($title);
        $this->body = $body;
        $this->tags = self::normalizeTags($tags);
        $this->collection = self::normalizeCollection($collection);
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    public function getCollection(): ?string
    {
        return $this->collection;
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
     * @param list<string>|null $tags pass null to leave tags untouched
     */
    public function update(
        string $title,
        string $body,
        ?array $tags,
        ?string $collection,
        \DateTimeImmutable $updatedAt,
    ): void {
        $this->title = self::normalizeTitle($title);
        $this->body = $body;
        if (null !== $tags) {
            $this->tags = self::normalizeTags($tags);
        }
        $this->collection = self::normalizeCollection($collection);
        $this->updatedAt = $updatedAt;
    }

    /**
     * The ADR-013 instance payload — i.e. the `data` half consumed by the
     * schema-driven renderer. Routing fields (`provider`, `type`, `id`) are
     * NOT included; the caller pairs this with the type's envelope and the
     * `Reference` id at the boundary.
     *
     * @return array{
     *     title: string,
     *     body: string,
     *     tags: list<string>,
     *     collection: ?string,
     *     created: string,
     *     modified: string,
     * }
     */
    public function toData(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'tags' => $this->tags,
            'collection' => $this->collection,
            'created' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'modified' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    private static function normalizeTitle(string $title): string
    {
        $title = trim($title);
        if ('' === $title) {
            throw new \InvalidArgumentException('core.document.title must be non-empty.');
        }
        if (mb_strlen($title) > 200) {
            throw new \InvalidArgumentException('core.document.title must be <= 200 chars.');
        }

        return $title;
    }

    /**
     * @param list<string> $tags
     *
     * @return list<string>
     */
    private static function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            $tag = trim($tag);
            if ('' === $tag) {
                continue;
            }
            if (mb_strlen($tag) > 64) {
                throw new \InvalidArgumentException('core.document.tag must be <= 64 chars.');
            }
            if (!\in_array($tag, $normalized, true)) {
                $normalized[] = $tag;
            }
        }

        return $normalized;
    }

    private static function normalizeCollection(?string $collection): ?string
    {
        if (null === $collection) {
            return null;
        }
        $collection = trim($collection);
        if ('' === $collection) {
            return null;
        }
        if (mb_strlen($collection) > 200) {
            throw new \InvalidArgumentException('core.document.collection must be <= 200 chars.');
        }

        return $collection;
    }
}
