<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * v0 user: console-minted, password-authenticated, session-logged-in (ADR-020).
 * Email is the user-facing identifier and the Symfony Security entity provider
 * lookup property. Stored lowercased; the case-fold happens at the write
 * boundary in the create command, not via citext.
 *
 * Identity is UUIDv7 surfaced as `core://users/{uuid}`.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_users_email', columns: ['email'])]
final class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 254)]
    private string $email;

    #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * Global default system prompt for this user (step-03 chunk D9, decision 5).
     * Per-user "operator setting" at its simplest — a nullable text column read
     * by {@see \App\Ai\Chat\SystemPromptResolver} when a thread has no override.
     * Promote to a settings table only when a second setting appears.
     */
    #[ORM\Column(name: 'system_prompt_default', type: 'text', nullable: true)]
    private ?string $systemPromptDefault = null;

    public function __construct(string $email, string $passwordHash, ClockInterface $clock)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->createdAt = $clock->now();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSystemPromptDefault(): ?string
    {
        return $this->systemPromptDefault;
    }

    public function setSystemPromptDefault(?string $systemPromptDefault): void
    {
        $this->systemPromptDefault = $systemPromptDefault;
    }

    public function coreUri(): string
    {
        return 'core://users/'.$this->id->toRfc4122();
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
    }
}
