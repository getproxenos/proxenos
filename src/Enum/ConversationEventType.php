<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Subset of `conversation_events.type` values implemented to date.
 *
 * Phase 0.2 added the four happy-path events
 * (`user_message_submitted`, `assistant_turn_created`,
 * `assistant_content_delta`, `assistant_turn_completed`); Phase 0.5 adds
 * `assistant_turn_failed` (ADR-025). The full vocabulary lives in
 * design-notes/event-sourced-conversations.md §3; the rest (tool_calls,
 * citations, artifacts, connector delivery, branching, compaction) is
 * deferred until later phases need them. Storage is a varchar so adding
 * cases is data-only, not a schema migration.
 */
enum ConversationEventType: string
{
    case USER_MESSAGE_SUBMITTED = 'user_message_submitted';
    case ASSISTANT_TURN_CREATED = 'assistant_turn_created';
    case ASSISTANT_CONTENT_DELTA = 'assistant_content_delta';
    case ASSISTANT_TURN_COMPLETED = 'assistant_turn_completed';
    case ASSISTANT_TURN_FAILED = 'assistant_turn_failed';
    case ASSISTANT_TURN_CANCELLED = 'assistant_turn_cancelled';
    case THREAD_ENTITY_ATTACHED = 'thread_entity_attached';
    case THREAD_ENTITY_DETACHED = 'thread_entity_detached';
    case THREAD_RENAMED = 'thread_renamed';
    case THREAD_ARCHIVED = 'thread_archived';
    case THREAD_SYSTEM_PROMPT_SET = 'thread_system_prompt_set';
}
