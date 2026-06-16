<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CoreDocument;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CoreDocumentTest extends TestCase
{
    public function testConstructorAssignsFieldsAndStampsTimestamps(): void
    {
        $id = Uuid::v7();
        $tenantId = Uuid::v7();
        $userId = Uuid::v7();
        $now = new \DateTimeImmutable('2026-06-16T10:00:00+00:00');

        $doc = new CoreDocument($id, $tenantId, $userId, 'Hello', 'body text', ['intro', 'demo'], 'notes', $now);

        self::assertSame($id, $doc->getId());
        self::assertSame($tenantId, $doc->getTenantId());
        self::assertSame($userId, $doc->getCreatedByUserId());
        self::assertSame('Hello', $doc->getTitle());
        self::assertSame('body text', $doc->getBody());
        self::assertSame(['intro', 'demo'], $doc->getTags());
        self::assertSame('notes', $doc->getCollection());
        self::assertEquals($now, $doc->getCreatedAt());
        self::assertEquals($now, $doc->getUpdatedAt());
    }

    public function testConstructorTrimsTitleAndRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CoreDocument(Uuid::v7(), Uuid::v7(), null, '   ', 'body', [], null, new \DateTimeImmutable());
    }

    public function testConstructorRejectsOverlongTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CoreDocument(Uuid::v7(), Uuid::v7(), null, str_repeat('x', 201), 'body', [], null, new \DateTimeImmutable());
    }

    public function testConstructorNormalizesTagsTrimDedupAndDropsEmpty(): void
    {
        $doc = new CoreDocument(
            Uuid::v7(),
            Uuid::v7(),
            null,
            'T',
            'b',
            ['  one  ', 'two', '', 'one', 'three'],
            null,
            new \DateTimeImmutable(),
        );

        self::assertSame(['one', 'two', 'three'], $doc->getTags());
    }

    public function testConstructorNormalizesCollectionEmptyStringToNull(): void
    {
        $doc = new CoreDocument(Uuid::v7(), Uuid::v7(), null, 'T', 'b', [], '   ', new \DateTimeImmutable());

        self::assertNull($doc->getCollection());
    }

    public function testUpdateRefreshesFieldsAndUpdatedAt(): void
    {
        $created = new \DateTimeImmutable('2026-06-16T10:00:00+00:00');
        $modified = new \DateTimeImmutable('2026-06-16T11:00:00+00:00');
        $doc = new CoreDocument(Uuid::v7(), Uuid::v7(), null, 'Old', 'old body', ['a'], 'inbox', $created);

        $doc->update('New', 'new body', ['b', 'c'], 'project-x', $modified);

        self::assertSame('New', $doc->getTitle());
        self::assertSame('new body', $doc->getBody());
        self::assertSame(['b', 'c'], $doc->getTags());
        self::assertSame('project-x', $doc->getCollection());
        self::assertEquals($created, $doc->getCreatedAt());
        self::assertEquals($modified, $doc->getUpdatedAt());
    }

    public function testUpdateLeavesTagsUntouchedWhenNullPassed(): void
    {
        $created = new \DateTimeImmutable('2026-06-16T10:00:00+00:00');
        $doc = new CoreDocument(Uuid::v7(), Uuid::v7(), null, 'T', 'b', ['keep'], null, $created);

        $doc->update('T', 'b2', null, null, $created);

        self::assertSame(['keep'], $doc->getTags());
    }

    public function testToDataMatchesAdr013SchemaDataHalf(): void
    {
        $created = new \DateTimeImmutable('2026-06-16T10:00:00+00:00');
        $modified = new \DateTimeImmutable('2026-06-16T11:30:00+00:00');
        $doc = new CoreDocument(Uuid::v7(), Uuid::v7(), null, 'Hello', 'body', ['a', 'b'], 'inbox', $created);
        $doc->update('Hello', 'body', null, 'inbox', $modified);

        self::assertSame(
            [
                'title' => 'Hello',
                'body' => 'body',
                'tags' => ['a', 'b'],
                'collection' => 'inbox',
                'created' => '2026-06-16T10:00:00+00:00',
                'modified' => '2026-06-16T11:30:00+00:00',
            ],
            $doc->toData(),
        );
    }
}
