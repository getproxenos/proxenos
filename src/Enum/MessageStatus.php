<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Status on the `messages` projection. `streaming` is the in-progress state
 * between `assistant_content_delta` and `assistant_turn_completed`; user
 * messages skip straight to `complete`; `failed` is set by the
 * assistant_turn_failed event family (0.3+); `cancelled` is set by
 * assistant_turn_cancelled when a turn is cooperatively stopped (step-03
 * chunk D7) — rendered distinctly from `failed` so the UI shows "stopped",
 * not "errored".
 */
enum MessageStatus: string
{
    case STREAMING = 'streaming';
    case COMPLETE = 'complete';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
