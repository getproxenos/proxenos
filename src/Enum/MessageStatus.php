<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Status on the `messages` projection. `streaming` is the in-progress state
 * between `assistant_content_delta` and `assistant_turn_completed`; user
 * messages skip straight to `complete`; `failed` reserves its slot for the
 * assistant_turn_failed event family (0.3+).
 */
enum MessageStatus: string
{
    case STREAMING = 'streaming';
    case COMPLETE = 'complete';
    case FAILED = 'failed';
}
