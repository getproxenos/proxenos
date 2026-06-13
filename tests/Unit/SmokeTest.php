<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trivial passing test — the Phase 0.0 "CI is green on a trivial test" gate.
 * Replace/extend as real units land.
 */
final class SmokeTest extends TestCase
{
    public function testTheHarnessRuns(): void
    {
        self::assertTrue(true);
    }
}
