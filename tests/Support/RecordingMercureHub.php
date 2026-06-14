<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\Update;

/**
 * In-process Mercure hub for tests — captures every publish so the test
 * can assert topic + payload without standing up a real broker. Mirrors
 * the spirit of `RecordingInMemoryPlatform`: replace the real adapter at
 * the service boundary, record calls in-memory.
 *
 * Used by the Mercure fan-out tests (handoff §2 "test credential-free by
 * asserting the publisher/hub is invoked with the right topic+payload
 * via a fake/in-memory hub").
 */
final class RecordingMercureHub implements HubInterface
{
    /** @var list<Update> */
    private array $published = [];

    public function getUrl(): string
    {
        return 'http://test/.well-known/mercure';
    }

    public function getPublicUrl(): string
    {
        return $this->getUrl();
    }

    public function getProvider(): TokenProviderInterface
    {
        return new StaticTokenProvider('test-jwt');
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        $this->published[] = $update;

        return 'urn:uuid:'.bin2hex(random_bytes(8));
    }

    /** @return list<Update> */
    public function published(): array
    {
        return $this->published;
    }

    public function reset(): void
    {
        $this->published = [];
    }
}
