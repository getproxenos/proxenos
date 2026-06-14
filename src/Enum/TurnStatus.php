<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle states the `turns` projection tracks per design-notes §4
 * ("pending / streaming / completed / failed / cancelled"). Phase 0.2 only
 * drives `pending → streaming → completed`; `failed`/`cancelled` reserve their
 * slot for 0.3+ (assistant_turn_failed, turn_cancel_requested events).
 */
enum TurnStatus: string
{
    case PENDING = 'pending';
    case STREAMING = 'streaming';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
