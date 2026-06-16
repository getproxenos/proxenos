<?php

declare(strict_types=1);

namespace App\Ai\Chat;

/**
 * One attached entity offered to the {@see ContextBudgetPlanner}, carrying its
 * serialization at each ADR-016 admission mode (`full` → `summary` →
 * `reference`). The planner walks the ladder top-down and admits the richest
 * shape that still fits the remaining budget.
 *
 * In the v0 vertical spine the assembler honors only the `pill` expansion
 * (transclusion guardrail), so `full` and `summary` both carry the pill
 * serialization and `reference` carries the bare identity line. When
 * transclusion lands, `full`/`summary` will carry richer renders — the ladder
 * itself does not change.
 */
final readonly class AttachmentCandidate
{
    public function __construct(
        public string $full,
        public string $summary,
        public string $reference,
    ) {
    }
}
