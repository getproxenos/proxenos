<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Chat;

use App\Ai\Chat\AdmittedEntity;
use App\Ai\Chat\AttachmentCandidate;
use App\Ai\Chat\ContextBudgetPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Context Budget Planner v0 (ADR-016, step-02 decision 8): the
 * `full → summary → reference` admission ladder and the `transclusions`
 * no-op seam.
 */
final class ContextBudgetPlannerTest extends TestCase
{
    public function testEstimateTokensIsFloorOfCharsOverFour(): void
    {
        self::assertSame(0, ContextBudgetPlanner::estimateTokens(''));
        self::assertSame(0, ContextBudgetPlanner::estimateTokens('abc'));
        self::assertSame(1, ContextBudgetPlanner::estimateTokens('abcd'));
        self::assertSame(2, ContextBudgetPlanner::estimateTokens('abcdefghi')); // 9/4 -> 2
    }

    public function testAdmitsFullWhenItFits(): void
    {
        $planner = new ContextBudgetPlanner();

        $admitted = $planner->admitAttachedEntities([
            new AttachmentCandidate(full: 'tiny', summary: 'x', reference: 'r'),
        ], budget: 100);

        self::assertCount(1, $admitted);
        self::assertSame(AdmittedEntity::MODE_FULL, $admitted[0]->mode);
        self::assertSame('tiny', $admitted[0]->text);
    }

    public function testDegradesFullThenSummaryThenReferenceAtTheBoundary(): void
    {
        $planner = new ContextBudgetPlanner();

        // Budget = 20 tokens (80 chars). estimateTokens = floor(strlen/4).
        $candidates = [
            // A: full=100ch(25t) > 20 -> no; summary=40ch(10t) <= 20 -> admit summary. remaining=10.
            new AttachmentCandidate(
                full: str_repeat('x', 100),
                summary: str_repeat('s', 40),
                reference: str_repeat('r', 4),
            ),
            // B: full=100ch(25t) > 10 -> no; summary=60ch(15t) > 10 -> no; reference=40ch(10t) <= 10 -> admit reference. remaining=0.
            new AttachmentCandidate(
                full: str_repeat('x', 100),
                summary: str_repeat('s', 60),
                reference: str_repeat('r', 40),
            ),
            // C: nothing fits in 0 -> dropped.
            new AttachmentCandidate(
                full: str_repeat('x', 8),
                summary: str_repeat('s', 8),
                reference: str_repeat('r', 8),
            ),
        ];

        $admitted = $planner->admitAttachedEntities($candidates, budget: 20);

        self::assertCount(2, $admitted, 'C does not fit and is dropped');
        self::assertSame(AdmittedEntity::MODE_SUMMARY, $admitted[0]->mode);
        self::assertSame(str_repeat('s', 40), $admitted[0]->text);
        self::assertSame(10, $admitted[0]->tokens);
        self::assertSame(AdmittedEntity::MODE_REFERENCE, $admitted[1]->mode);
        self::assertSame(str_repeat('r', 40), $admitted[1]->text);
        self::assertSame(10, $admitted[1]->tokens);
    }

    public function testEmptyCandidatesAdmitNothing(): void
    {
        self::assertSame([], new ContextBudgetPlanner()->admitAttachedEntities([]));
    }

    public function testTransclusionsAreANoOpAndAdmitZero(): void
    {
        $planner = new ContextBudgetPlanner();

        // Even handed real-looking candidates and a generous budget, the
        // transclusion seam admits nothing in v0 (transclusion guardrail).
        self::assertSame([], $planner->admitTransclusions([
            new AttachmentCandidate('full', 'summary', 'reference'),
        ], budget: 100_000));
        self::assertSame([], $planner->admitTransclusions([]));
    }
}
