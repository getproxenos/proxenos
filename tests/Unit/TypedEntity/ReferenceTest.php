<?php

declare(strict_types=1);

namespace App\Tests\Unit\TypedEntity;

use App\TypedEntity\Reference;
use PHPUnit\Framework\TestCase;

final class ReferenceTest extends TestCase
{
    public function testDefaultsToPillExpansionAndOmitsOptionals(): void
    {
        $ref = new Reference('core', 'core.document', 'abc');

        self::assertSame('pill', $ref->expansion);
        self::assertNull($ref->marker);
        self::assertNull($ref->label);
        self::assertSame(
            [
                'provider' => 'core',
                'type' => 'core.document',
                'id' => 'abc',
                'resolved' => false,
                'expansion' => 'pill',
            ],
            $ref->toArray(),
        );
    }

    public function testRejectsEmptyRoutingFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Reference('', 'core.document', 'abc');
    }

    public function testRejectsUnknownExpansion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore-next-line — intentional bad value to assert validation
        new Reference('core', 'core.document', 'abc', expansion: 'oversized');
    }

    public function testIdentityKeyIsTheByteEqualityTriple(): void
    {
        $a = new Reference('core', 'core.document', '019ed...id');
        $b = new Reference('core', 'core.document', '019ed...id');
        $c = new Reference('core', 'core.document', 'different');

        self::assertSame($a->identityKey(), $b->identityKey());
        self::assertNotSame($a->identityKey(), $c->identityKey());
    }

    public function testRoundTripsThroughFromArrayAndToArray(): void
    {
        $ref = new Reference(
            provider: 'core',
            type: 'core.document',
            id: 'opaque-id',
            resolved: true,
            marker: '[[link]]',
            label: 'Doc',
            expansion: 'summary',
            snapshot: ['title' => 'X'],
            target: ['extra' => 1],
        );

        $array = $ref->toArray();
        $restored = Reference::fromArray($array);

        self::assertSame($ref->provider, $restored->provider);
        self::assertSame($ref->type, $restored->type);
        self::assertSame($ref->id, $restored->id);
        self::assertSame($ref->resolved, $restored->resolved);
        self::assertSame($ref->marker, $restored->marker);
        self::assertSame($ref->label, $restored->label);
        self::assertSame($ref->expansion, $restored->expansion);
        self::assertSame($ref->snapshot, $restored->snapshot);
        self::assertSame($ref->target, $restored->target);
    }

    public function testFromArrayDefaultsExpansionToPillWhenMissing(): void
    {
        $ref = Reference::fromArray([
            'provider' => 'core',
            'type' => 'core.document',
            'id' => 'x',
            'resolved' => true,
        ]);

        self::assertSame('pill', $ref->expansion);
        self::assertTrue($ref->resolved);
    }
}
