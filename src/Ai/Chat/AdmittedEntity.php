<?php

declare(strict_types=1);

namespace App\Ai\Chat;

/**
 * The {@see ContextBudgetPlanner}'s decision for a single attached entity: the
 * ADR-016 mode it was admitted at, the serialized text at that mode, and the
 * estimated token cost charged against the budget.
 */
final readonly class AdmittedEntity
{
    public const string MODE_FULL = 'full';
    public const string MODE_SUMMARY = 'summary';
    public const string MODE_REFERENCE = 'reference';

    /**
     * @param 'full'|'summary'|'reference' $mode
     */
    public function __construct(
        public string $mode,
        public string $text,
        public int $tokens,
    ) {
    }
}
