<?php

declare(strict_types=1);

namespace App\Conversation\Event\Payload;

use App\Enum\ConversationEventType;

/**
 * Payload for `thread_renamed` (step-03 chunk D2, decision 3). Carries the new
 * thread title; the fold writes it onto the `threads` projection via
 * `Thread::setTitle()`. The title is the only field — the thread id lives on
 * the envelope. Named explicitly (not a generic `thread_metadata_changed`) so
 * the event vocabulary stays self-describing (decision 10).
 */
final readonly class ThreadRenamed implements EventPayload
{
    public function __construct(
        public string $title,
    ) {
        if ('' === trim($title)) {
            throw new \InvalidArgumentException('thread_renamed.title must be non-empty.');
        }
    }

    public function type(): ConversationEventType
    {
        return ConversationEventType::THREAD_RENAMED;
    }

    public function toArray(): array
    {
        return ['title' => $this->title];
    }
}
