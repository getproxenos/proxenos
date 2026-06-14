<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MembershipRole;
use App\Repository\MembershipRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Models user↔tenant membership even though v0 is one-user-one-tenant. ADR-005
 * (permissions modeled even if all `owner`) applied concretely: a future
 * second user / second tenant is a row insert, not an entity introduction.
 */
#[ORM\Entity(repositoryClass: MembershipRepository::class)]
#[ORM\Table(name: 'memberships')]
#[ORM\UniqueConstraint(name: 'uniq_memberships_user_tenant', columns: ['user_id', 'tenant_id'])]
#[ORM\Index(name: 'idx_memberships_tenant', columns: ['tenant_id'])]
final class Membership
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: 'string', length: 32, enumType: MembershipRole::class)]
    private MembershipRole $role;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, Tenant $tenant, MembershipRole $role, ClockInterface $clock)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tenant = $tenant;
        $this->role = $role;
        $this->createdAt = $clock->now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getRole(): MembershipRole
    {
        return $this->role;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
