<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * v0 collapses account/tenant/workspace into a single concept (ADR-021).
 * Its own table so a later split (tenant = org/billing, workspace = container)
 * is a migration, not a redesign. UI copy may say "account" or "workspace";
 * the schema and code say `Tenant`.
 *
 * Identity is UUIDv7 (time-ordered) surfaced as `core://tenants/{uuid}`.
 */
#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenants')]
#[ORM\UniqueConstraint(name: 'uniq_tenants_slug', columns: ['slug'])]
final class Tenant
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 64)]
    private string $slug;

    #[ORM\Column(type: 'string', length: 200)]
    private string $name;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $slug, string $name, ClockInterface $clock)
    {
        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->name = $name;
        $this->createdAt = $clock->now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function coreUri(): string
    {
        return 'core://tenants/'.$this->id->toRfc4122();
    }
}
