<?php

declare(strict_types=1);

namespace App\Conversation\Title;

/**
 * v0 title source (step-03 chunk D4, OQ3 lean): the first-N-chars heuristic
 * over the first user message. Trims, collapses runs of whitespace (including
 * newlines) to single spaces, and clamps to the `threads.title` projection
 * column width, ellipsizing when it truncates. Deterministic and
 * credential-free — the documented acceptable stand-in until the F1
 * `proxenos.task.summarize` source lands (do not block Track D on F1).
 */
final class HeuristicThreadTitleSource implements ThreadTitleSource
{
    /**
     * Matches the `threads.title` column (varchar(200), see {@see \App\Entity\Thread}).
     * The title MUST end up at or under this length — the auto-titler appends it
     * straight onto the event log, bypassing the controller's rename guard.
     */
    public const int MAX_TITLE_LENGTH = 200;

    private const string ELLIPSIS = '…';

    public function titleFor(string $firstUserMessage): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $firstUserMessage));
        if ('' === $collapsed) {
            return '';
        }

        if (mb_strlen($collapsed) > self::MAX_TITLE_LENGTH) {
            // Reserve one character for the ellipsis so the result is exactly
            // MAX_TITLE_LENGTH; rtrim drops a trailing space if the cut lands on
            // one (avoids "word …").
            $collapsed = rtrim(mb_substr($collapsed, 0, self::MAX_TITLE_LENGTH - 1)).self::ELLIPSIS;
        }

        return $collapsed;
    }
}
