<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Event\Payload;

use App\Conversation\Event\Payload\ThreadEntityAttached;
use App\Conversation\Event\Payload\ThreadEntityDetached;
use App\Enum\ConversationEventType;
use App\TypedEntity\Reference;
use PHPUnit\Framework\TestCase;

/**
 * Locks the attach/pin payload contracts (step-02 chunk 6). The attach payload
 * carries the full reference envelope so the fold can reconstruct it
 * byte-faithfully; the detach payload is just the identity triple.
 */
final class ThreadEntityPayloadTest extends TestCase
{
    public function testAttachedSerializesReferenceEnvelope(): void
    {
        $reference = new Reference('core', 'core.document', 'opaque-id', resolved: true, label: 'Doc');
        $payload = new ThreadEntityAttached($reference);

        self::assertSame(ConversationEventType::THREAD_ENTITY_ATTACHED, $payload->type());
        self::assertSame(
            [
                'reference' => [
                    'provider' => 'core',
                    'type' => 'core.document',
                    'id' => 'opaque-id',
                    'resolved' => true,
                    'expansion' => 'pill',
                    'label' => 'Doc',
                ],
            ],
            $payload->toArray(),
        );
    }

    public function testAttachedIncludesAttachedAtWhenSupplied(): void
    {
        $reference = new Reference('core', 'core.document', 'id');
        $payload = new ThreadEntityAttached($reference, new \DateTimeImmutable('2026-06-16T12:00:00+00:00'));

        $array = $payload->toArray();
        self::assertArrayHasKey('attached_at', $array);
        self::assertSame('2026-06-16T12:00:00+00:00', $array['attached_at']);
    }

    public function testAttachedRoundTripsReferenceWithOpaqueIdExpansionAndSnapshot(): void
    {
        $reference = new Reference(
            provider: 'core',
            type: 'core.document',
            id: "\x00opaque\xffbytes",
            resolved: true,
            expansion: 'summary',
            snapshot: ['title' => 'Snap', 'nested' => ['k' => 'v']],
            target: ['extra' => 1],
        );

        $restored = Reference::fromArray($reference->toArray());

        self::assertSame($reference->id, $restored->id);
        self::assertSame($reference->expansion, $restored->expansion);
        self::assertSame($reference->snapshot, $restored->snapshot);
        self::assertSame($reference->target, $restored->target);
        self::assertSame($reference->resolved, $restored->resolved);
    }

    public function testDetachedSerializesIdentityTriple(): void
    {
        $payload = new ThreadEntityDetached('core', 'core.document', 'opaque-id');

        self::assertSame(ConversationEventType::THREAD_ENTITY_DETACHED, $payload->type());
        self::assertSame(
            ['provider' => 'core', 'type' => 'core.document', 'id' => 'opaque-id'],
            $payload->toArray(),
        );
    }

    public function testDetachedRejectsEmptyTripleField(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ThreadEntityDetached('core', 'core.document', '');
    }
}
