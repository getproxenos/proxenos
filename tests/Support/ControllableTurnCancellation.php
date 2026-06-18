<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Ai\Chat\TurnCancellation;
use Symfony\Component\Uid\Uuid;

/**
 * Controllable {@see TurnCancellation} double (step-03 chunk D7). Replaces the
 * cache-backed production binding in config/services_test.yaml so the
 * cooperative-cancel path is exercised credential-free and deterministically
 * (decision 4) — no real cache pool, no timing races.
 *
 * Two modes:
 *  - explicit `request()`/`clear()` mirror the production contract (the cancel
 *    endpoint test asserts the signal is set this way);
 *  - `tripAfterCalls(int $n)` arms the double so `isRequested()` returns true
 *    starting at the (n+1)-th call — letting a loop test stop the stream after
 *    a chosen number of coalesced flushes without touching real cache.
 *
 * State is static (mirrors {@see RecordingInMemoryPlatform}) because the
 * container hands a fresh instance per kernel boot; `reset()` clears it. Tests
 * that arm the double MUST reset() in tearDown so the armed state cannot leak
 * into another test under random execution order.
 */
final class ControllableTurnCancellation implements TurnCancellation
{
    /** @var array<string, bool> */
    private static array $requested = [];

    private static ?int $tripAfterCalls = null;

    private static int $isRequestedCalls = 0;

    public function request(Uuid $turnId): void
    {
        self::$requested[$turnId->toRfc4122()] = true;
    }

    public function isRequested(Uuid $turnId): bool
    {
        ++self::$isRequestedCalls;

        if (null !== self::$tripAfterCalls && self::$isRequestedCalls > self::$tripAfterCalls) {
            return true;
        }

        return self::$requested[$turnId->toRfc4122()] ?? false;
    }

    public function clear(Uuid $turnId): void
    {
        unset(self::$requested[$turnId->toRfc4122()]);
    }

    /**
     * Arm the double: every `isRequested()` call after the first `$n` returns
     * true. `tripAfterCalls(0)` trips on the very first poll (i.e. after the
     * first coalesced flush).
     */
    public function tripAfterCalls(int $n): void
    {
        self::$tripAfterCalls = max(0, $n);
    }

    public function reset(): void
    {
        self::$requested = [];
        self::$tripAfterCalls = null;
        self::$isRequestedCalls = 0;
    }
}
