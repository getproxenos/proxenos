<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\UuidV7;

final class UserTest extends TestCase
{
    public function testImplementsSecurityContracts(): void
    {
        $user = new User('beau@example.com', '$2y$10$abcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcab', new MockClock());

        self::assertInstanceOf(UserInterface::class, $user);
        self::assertInstanceOf(PasswordAuthenticatedUserInterface::class, $user);
    }

    public function testGetUserIdentifierIsEmailAndRolesIncludesRoleUser(): void
    {
        $user = new User('beau@example.com', 'hash', new MockClock());

        self::assertSame('beau@example.com', $user->getUserIdentifier());
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertSame('hash', $user->getPassword());
    }

    public function testCoreUriShape(): void
    {
        $user = new User('beau@example.com', 'hash', new MockClock());

        self::assertMatchesRegularExpression(
            '#^core://users/[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$#',
            $user->coreUri(),
        );
        self::assertInstanceOf(UuidV7::class, $user->getId());
    }

    public function testEraseCredentialsIsANoOp(): void
    {
        $user = new User('beau@example.com', 'hash', new MockClock());
        $user->eraseCredentials();
        self::assertSame('hash', $user->getPassword());
    }
}
