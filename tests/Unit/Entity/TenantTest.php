<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class TenantTest extends TestCase
{
    public function testConstructorAssignsUuidV7AndStampsCreatedAt(): void
    {
        $clock = new MockClock('2026-06-14T12:00:00+00:00');

        $tenant = new Tenant('personal', 'Personal', $clock);

        self::assertSame('personal', $tenant->getSlug());
        self::assertSame('Personal', $tenant->getName());
        self::assertInstanceOf(UuidV7::class, $tenant->getId());
        self::assertSame('2026-06-14T12:00:00+00:00', $tenant->getCreatedAt()->format(\DATE_ATOM));
    }

    public function testCoreUriUsesRfc4122Form(): void
    {
        $tenant = new Tenant('personal', 'Personal', new MockClock('2026-06-14T12:00:00+00:00'));

        $uri = $tenant->coreUri();
        $rfc = $tenant->getId()->toRfc4122();

        self::assertSame('core://tenants/'.$rfc, $uri);
        self::assertMatchesRegularExpression(
            '#^core://tenants/[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$#',
            $uri,
        );
        self::assertTrue(Uuid::isValid(substr($uri, strlen('core://tenants/'))));
    }
}
