<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Actor vocabulary from the canonical event schema (design-notes/
 * event-sourced-conversations.md §2). All five values reserve their slot now;
 * only `user` and `assistant` are emitted in Phase 0.2.
 */
enum ActorType: string
{
    case USER = 'user';
    case ASSISTANT = 'assistant';
    case CONNECTOR = 'connector';
    case SYSTEM = 'system';
    case EXTENSION = 'extension';
}
