<?php

declare(strict_types=1);

namespace App\Conversation\Title;

use App\Ai\ModelProfile\ModelProfileResolver;
use App\Ai\ModelProfile\UnknownModelProfile;
use App\Conversation\ThreadLifecycleService;
use Symfony\Component\Uid\Uuid;

/**
 * Auto-titles a thread the first time a user submits to it (step-03 chunk D4).
 * Triggered from {@see \App\Controller\Api\ApiChatController::submit()} AFTER
 * the turn loop runs, gated on "thread had no title/row before this turn" so it
 * fires once per thread. Deliberately OUT of `ChatRespondLoop` — the core loop
 * stays untouched.
 *
 * The title is computed by a pluggable {@see ThreadTitleSource} and appended as
 * a `thread_renamed` event through {@see ThreadLifecycleService::rename()}
 * (reusing the D2 event/payload/fold; this invents no new event). The rename
 * carries no `actorId`, so it records as a SYSTEM actor — the title is
 * machine-derived, not a user edit.
 *
 * Source selection (OQ3): the heuristic is the v0 default. When
 * `PROXENOS_AUTOTITLE_MODEL_PROFILE` names an F1 `proxenos.task.summarize`
 * profile that resolves, a model-backed source would take over; until F1 ships
 * (or when the profile is absent/unknown), the heuristic is used. The profile
 * is resolved by name only — model selection stays operator-config, never user
 * choice (decision 6 / ADR-008).
 */
final class ThreadAutoTitler
{
    public function __construct(
        private readonly ThreadLifecycleService $lifecycle,
        private readonly HeuristicThreadTitleSource $heuristic,
        private readonly ModelProfileResolver $profiles,
        private readonly string $modelProfile = '',
    ) {
    }

    public function autoTitle(Uuid $threadId, Uuid $tenantId, string $firstUserMessage): void
    {
        $title = $this->titleSource()->titleFor($firstUserMessage);
        if ('' === trim($title)) {
            // Nothing titleable; skip silently. `ThreadRenamed` rejects blanks
            // anyway, so this also keeps the event log clean.
            return;
        }

        $this->lifecycle->rename($threadId, $tenantId, $title);
    }

    private function titleSource(): ThreadTitleSource
    {
        if ('' === $this->modelProfile) {
            return $this->heuristic;
        }

        try {
            $this->profiles->resolve($this->modelProfile);
        } catch (UnknownModelProfile) {
            // F1/the profile is absent — fall back to the heuristic (OQ3 lean).
            return $this->heuristic;
        }

        // F1 seam: once a `proxenos.task.summarize`-backed ThreadTitleSource
        // lands, construct and return it here from the resolved model. Until F1
        // ships, the heuristic is the only concrete source, so use it.
        return $this->heuristic;
    }
}
