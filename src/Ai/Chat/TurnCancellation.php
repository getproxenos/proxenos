<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use Symfony\Component\Uid\Uuid;

/**
 * Cross-request cooperative-cancellation signal for a streaming turn (step-03
 * chunk D7, decision 4 / Open question 2).
 *
 * The cancel request (`POST …/cancel`) and the streaming submit
 * (`ChatRespondLoop::execute`) are CONCURRENT HTTP requests, so an in-process
 * flag cannot reach the loop. The signal is therefore a small out-of-band
 * store the controller writes and the loop polls on each coalesced flush — NOT
 * a `turn_cancel_requested` event on the conversation log (the log stays
 * terminal-events-only; the requested-marker-as-event alternative is rejected,
 * Open question 2). The production binding is cache-backed
 * ({@see CacheTurnCancellation}); tests use a controllable double.
 */
interface TurnCancellation
{
    /**
     * Mark the given turn as cancellation-requested. Idempotent.
     */
    public function request(Uuid $turnId): void;

    /**
     * Whether a cancellation has been requested for the given turn.
     */
    public function isRequested(Uuid $turnId): bool;

    /**
     * Clear the signal for the given turn. The loop calls this once it has
     * acted on the request (appended `assistant_turn_cancelled`) so a stale
     * marker cannot leak into a later turn that reuses cache space.
     */
    public function clear(Uuid $turnId): void;
}
