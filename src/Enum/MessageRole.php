<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Role on the `messages` projection. Phase 0.2 only folds `user` and
 * `assistant`; `system`/`tool` roles wait until those event families land.
 */
enum MessageRole: string
{
    case USER = 'user';
    case ASSISTANT = 'assistant';
}
