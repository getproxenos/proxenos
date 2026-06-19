<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Chat;

use App\Ai\Chat\CacheTurnCancellation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/**
 * Cross-process visibility of the cooperative-cancel signal (step-04). The
 * multi-worker prod invariant is: a cancel POST handled by one PHP worker must
 * be observable by the *different* worker draining the stream. Prod gets that
 * from a shared Redis pool; here we prove the same PSR-6 contract over a shared
 * filesystem backing store — two independent {@see FilesystemAdapter} instances
 * (each modelling a separate worker process) pointed at one directory.
 *
 * This is the credential-free stand-in for "a second worker sees the flag": no
 * Redis, no network, deterministic. {@see CacheTurnCancellation} is unchanged —
 * the swap is pure infrastructure, so any shared-backing pool behaves the same.
 */
final class CacheTurnCancellationCrossProcessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/proxenos_cancel_xproc_'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testFreshPoolInstanceSeesACancelRequestFromAnother(): void
    {
        $turnId = Uuid::v7();

        // Worker A: handles the cancel POST and writes the flag.
        $workerA = new CacheTurnCancellation($this->pool());
        $workerA->request($turnId);

        // Worker B: a *fresh* pool instance over the same store — the worker
        // draining the stream. It must observe A's request.
        $workerB = new CacheTurnCancellation($this->pool());
        self::assertTrue($workerB->isRequested($turnId), 'a second worker must see the cancel flag through the shared store');
    }

    public function testClearByOneWorkerIsVisibleToAnother(): void
    {
        $turnId = Uuid::v7();

        $workerA = new CacheTurnCancellation($this->pool());
        $workerA->request($turnId);

        // Worker B clears once the cancellation is handled; a later read from a
        // third fresh instance must no longer see it (no leaked flag).
        $workerB = new CacheTurnCancellation($this->pool());
        $workerB->clear($turnId);

        $workerC = new CacheTurnCancellation($this->pool());
        self::assertFalse($workerC->isRequested($turnId), 'a cleared flag must not linger for other workers');
    }

    public function testOneWorkersRequestDoesNotTripAnotherTurn(): void
    {
        $requested = Uuid::v7();
        $other = Uuid::v7();

        new CacheTurnCancellation($this->pool())->request($requested);

        $reader = new CacheTurnCancellation($this->pool());
        self::assertTrue($reader->isRequested($requested));
        self::assertFalse($reader->isRequested($other), 'cancellation is scoped per turn across workers too');
    }

    /**
     * A new pool instance over the shared directory — same namespace + dir, so
     * it reads/writes the same files. Models a separate worker process holding
     * its own pool object but the same backing store (Redis in prod).
     */
    private function pool(): FilesystemAdapter
    {
        return new FilesystemAdapter('turn_cancel_xproc', 0, $this->dir);
    }
}
