<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Title;

use App\Conversation\Title\HeuristicThreadTitleSource;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the v0 auto-title transform (step-03 chunk D4): trim,
 * collapse whitespace, clamp to the 200-char column, ellipsize on truncation.
 */
final class HeuristicThreadTitleSourceTest extends TestCase
{
    private HeuristicThreadTitleSource $heuristic;

    protected function setUp(): void
    {
        $this->heuristic = new HeuristicThreadTitleSource();
    }

    public function testTrimsAndKeepsShortMessageVerbatim(): void
    {
        self::assertSame('Hello there', $this->heuristic->titleFor('  Hello there  '));
    }

    public function testCollapsesInternalWhitespaceIncludingNewlines(): void
    {
        self::assertSame('one two three', $this->heuristic->titleFor("one   two\n\t three"));
    }

    public function testEmptyOrWhitespaceOnlyYieldsEmptyString(): void
    {
        self::assertSame('', $this->heuristic->titleFor(''));
        self::assertSame('', $this->heuristic->titleFor("   \n\t  "));
    }

    public function testClampsToTwoHundredCharsAndEllipsizes(): void
    {
        $long = str_repeat('a', 250);

        $title = $this->heuristic->titleFor($long);

        self::assertSame(HeuristicThreadTitleSource::MAX_TITLE_LENGTH, mb_strlen($title));
        self::assertSame(200, mb_strlen($title));
        self::assertStringEndsWith('…', $title);
        // 199 content chars + one ellipsis.
        self::assertSame(str_repeat('a', 199).'…', $title);
    }

    public function testExactlyTwoHundredCharsIsNotEllipsized(): void
    {
        $exact = str_repeat('b', 200);

        $title = $this->heuristic->titleFor($exact);

        self::assertSame($exact, $title);
        self::assertStringEndsNotWith('…', $title);
    }
}
