<?php

declare(strict_types=1);

namespace App\Ai\Chat;

/**
 * Context Budget Planner v0 (ADR-016, step-02 decision 8). Decides how much of
 * each attached entity is admitted into the prompt under a fixed token budget.
 * Two budget classes exist in v0:
 *
 *  - {@see self::admitAttachedEntities()} — the real one. Walks the ADR-016
 *    `full → summary → reference` ladder per entity (lines 191–210 of
 *    `design-notes/context-window-management.md`) and admits the richest shape
 *    that still fits the remaining budget; degrades when it does not; drops the
 *    entity entirely if even the bare `reference` line will not fit.
 *
 *  - {@see self::admitTransclusions()} — the transclusion seam, kept as a
 *    deliberate **no-op placeholder** (admits zero tokens) per the transclusion
 *    guardrail. Deleting the class would mean re-architecting admission when
 *    transclusion returns; admitting zero today costs nothing and keeps the
 *    seam open.
 *
 * Token estimation is the v0 `floor(strlen / 4)` heuristic — good enough to
 * prove the admission seam against real char counts; a real tokenizer is a
 * later refinement.
 */
final class ContextBudgetPlanner
{
    /**
     * Default `attached_entities` budget in estimated tokens. ~4000 tokens fits
     * one small `core.document` at `full`; larger entities degrade to
     * `summary`, very large ones to `reference` (open question 3 lean).
     */
    public const int DEFAULT_ATTACHED_ENTITIES_BUDGET = 4000;

    /**
     * The ladder, richest first.
     *
     * @var list<'full'|'summary'|'reference'>
     */
    private const array LADDER = [
        AdmittedEntity::MODE_FULL,
        AdmittedEntity::MODE_SUMMARY,
        AdmittedEntity::MODE_REFERENCE,
    ];

    /**
     * Admit attached entities, in the given order, under a shared token budget.
     *
     * @param list<AttachmentCandidate> $candidates in admission (attach) order
     *
     * @return list<AdmittedEntity>
     */
    public function admitAttachedEntities(array $candidates, int $budget = self::DEFAULT_ATTACHED_ENTITIES_BUDGET): array
    {
        $remaining = $budget;
        $admitted = [];

        foreach ($candidates as $candidate) {
            foreach (self::LADDER as $mode) {
                $text = $this->textForMode($candidate, $mode);
                $tokens = self::estimateTokens($text);
                if ($tokens <= $remaining) {
                    $remaining -= $tokens;
                    $admitted[] = new AdmittedEntity($mode, $text, $tokens);
                    continue 2;
                }
            }
            // Even the bare reference line will not fit: drop the entity. v0
            // records nothing extra; the omission is simply absence.
        }

        return $admitted;
    }

    /**
     * Transclusion budget class — NO-OP placeholder (transclusion guardrail).
     * Admits zero transcluded tokens in v0; present so reviving transclusion is
     * an additive change, not an admission re-architecture.
     *
     * @param list<mixed> $candidates
     *
     * @return list<never>
     */
    public function admitTransclusions(array $candidates, int $budget = 0): array
    {
        return [];
    }

    /**
     * v0 token heuristic: `floor(strlen / 4)` (open question 3).
     */
    public static function estimateTokens(string $text): int
    {
        return intdiv(\strlen($text), 4);
    }

    /**
     * @param 'full'|'summary'|'reference' $mode
     */
    private function textForMode(AttachmentCandidate $candidate, string $mode): string
    {
        return match ($mode) {
            AdmittedEntity::MODE_FULL => $candidate->full,
            AdmittedEntity::MODE_SUMMARY => $candidate->summary,
            AdmittedEntity::MODE_REFERENCE => $candidate->reference,
        };
    }
}
