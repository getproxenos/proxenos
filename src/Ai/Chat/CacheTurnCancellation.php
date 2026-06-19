<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Production {@see TurnCancellation} backed by the default app cache pool
 * (`cache.app`, autowired as {@see CacheItemPoolInterface}; configured in
 * config/packages/cache.yaml). A cancel request stores a short-lived flag
 * keyed by turn id; the loop polls and clears it.
 *
 * TTL is intentionally short: a cancellation is only meaningful for the
 * lifetime of an in-flight turn. The flag self-expires so an abandoned request
 * (the turn already finished, or the loop died before clearing) cannot linger.
 */
final class CacheTurnCancellation implements TurnCancellation
{
    /**
     * Cancellation is only relevant while a turn streams; a few minutes is far
     * longer than any realistic turn yet short enough that a leaked flag
     * self-clears.
     */
    private const int TTL_SECONDS = 300;

    private const string KEY_PREFIX = 'turn_cancel.';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function request(Uuid $turnId): void
    {
        $item = $this->cache->getItem($this->key($turnId));
        $item->set(true);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    public function isRequested(Uuid $turnId): bool
    {
        return $this->cache->getItem($this->key($turnId))->isHit();
    }

    public function clear(Uuid $turnId): void
    {
        $this->cache->deleteItem($this->key($turnId));
    }

    private function key(Uuid $turnId): string
    {
        // RFC4122 form (lowercase hex + dashes) is a valid PSR-6 key.
        return self::KEY_PREFIX.$turnId->toRfc4122();
    }
}
