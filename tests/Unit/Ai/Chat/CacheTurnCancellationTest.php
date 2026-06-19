<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Chat;

use App\Ai\Chat\CacheTurnCancellation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

/**
 * Behavior of the cache-backed cooperative-cancel store (step-03 chunk D7).
 * Backed here by an in-memory PSR-6 {@see ArrayAdapter} — same contract as the
 * production `cache.app` pool, no filesystem.
 */
final class CacheTurnCancellationTest extends TestCase
{
    public function testUnrequestedTurnIsNotRequested(): void
    {
        $store = new CacheTurnCancellation(new ArrayAdapter());

        self::assertFalse($store->isRequested(Uuid::v7()));
    }

    public function testRequestThenIsRequested(): void
    {
        $store = new CacheTurnCancellation(new ArrayAdapter());
        $turnId = Uuid::v7();

        $store->request($turnId);

        self::assertTrue($store->isRequested($turnId));
    }

    public function testRequestIsScopedPerTurn(): void
    {
        $store = new CacheTurnCancellation(new ArrayAdapter());
        $requested = Uuid::v7();
        $other = Uuid::v7();

        $store->request($requested);

        self::assertTrue($store->isRequested($requested));
        self::assertFalse($store->isRequested($other), 'a request for one turn must not trip another');
    }

    public function testClearRemovesTheSignal(): void
    {
        $store = new CacheTurnCancellation(new ArrayAdapter());
        $turnId = Uuid::v7();

        $store->request($turnId);
        $store->clear($turnId);

        self::assertFalse($store->isRequested($turnId));
    }
}
