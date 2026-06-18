<?php

declare(strict_types=1);

namespace App\Conversation\Title;

/**
 * Pluggable source of a thread title (step-03 chunk D4, OQ3). The v0
 * implementation is the deterministic first-N-chars heuristic
 * ({@see HeuristicThreadTitleSource}); the thin seam exists so an F1
 * `proxenos.task.summarize`-backed source can drop in later
 * ({@see ThreadAutoTitler::titleSource()}) without touching the trigger or the
 * event path. Model selection stays operator-only (decision 6 / ADR-008) — no
 * user choice ever flows through here.
 */
interface ThreadTitleSource
{
    /**
     * Compute a thread title from the first user message. MUST return a value
     * that fits the `threads.title` projection column (varchar(200)); callers
     * append it verbatim through `thread_renamed`. Returns an empty string when
     * the message carries nothing titleable (caller skips the rename then).
     */
    public function titleFor(string $firstUserMessage): string;
}
