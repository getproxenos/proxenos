<?php

declare(strict_types=1);

namespace App\Tests\Unit\TypedEntity\Renderer;

use App\TypedEntity\Renderer\JsonPointer;
use PHPUnit\Framework\TestCase;

final class JsonPointerTest extends TestCase
{
    public function testEmptyPointerReturnsRoot(): void
    {
        self::assertSame(['a' => 1], JsonPointer::get(['a' => 1], ''));
    }

    public function testSingleSegmentObject(): void
    {
        self::assertSame('Hello', JsonPointer::get(['title' => 'Hello'], '/title'));
    }

    public function testListIndexing(): void
    {
        self::assertSame('b', JsonPointer::get(['tags' => ['a', 'b', 'c']], '/tags/1'));
    }

    public function testMissingSegmentReturnsNull(): void
    {
        self::assertNull(JsonPointer::get(['a' => 1], '/missing'));
        self::assertNull(JsonPointer::get(['tags' => ['x']], '/tags/9'));
    }

    public function testRejectsPointerNotStartingWithSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        JsonPointer::get(['a' => 1], 'title');
    }

    public function testEscapedSegments(): void
    {
        $data = ['a/b' => 1, 'c~d' => 2];

        self::assertSame(1, JsonPointer::get($data, '/a~1b'));
        self::assertSame(2, JsonPointer::get($data, '/c~0d'));
    }
}
