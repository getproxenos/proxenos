<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Subset of `conversation_events.type` values that Phase 0.2 implements.
 * The full vocabulary lives in design-notes/event-sourced-conversations.md §3;
 * the rest (tool_calls, citations, artifacts, connector delivery, branching,
 * compaction) is deferred until later phases need them (handoff-conversation-
 * message-model.md). Storage is a varchar so adding cases is data-only, not a
 * schema migration.
 */
enum ConversationEventType: string
{
    case USER_MESSAGE_SUBMITTED = 'user_message_submitted';
    case ASSISTANT_TURN_CREATED = 'assistant_turn_created';
    case ASSISTANT_CONTENT_DELTA = 'assistant_content_delta';
    case ASSISTANT_TURN_COMPLETED = 'assistant_turn_completed';
}
