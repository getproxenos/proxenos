<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Conversation\Event\EventEnvelope;
use App\Conversation\Event\Payload\ThreadArchived;
use App\Conversation\Event\Payload\ThreadRenamed;
use App\Conversation\Event\Payload\ThreadSystemPromptSet;
use App\Enum\ActorType;
use Symfony\Component\Uid\Uuid;

/**
 * Application service over the event-sourced thread lifecycle (step-03 chunk
 * D2). Mirrors {@see ThreadAttachmentService}: writes go through
 * `EventAppender` (canonical log → inline fold → Mercure fan-out), reads come
 * from the `threads` projection.
 *
 * Rename appends `thread_renamed` (folds to `Thread::setTitle`); archive
 * appends `thread_archived` (folds to `Thread::markArchived` — a soft hide,
 * never a delete, decision 10). The Mercure fan-out is what lets the SPA's
 * thread list update live when another tab renames or archives.
 *
 * `tenantId` is threaded through because the `EventEnvelope` needs it — an
 * expected extension of the workplan's short signatures, not a conflict.
 */
final class ThreadLifecycleService
{
    public function __construct(
        private readonly EventAppender $appender,
    ) {
    }

    public function rename(Uuid $threadId, Uuid $tenantId, string $title, ?string $actorId = null): void
    {
        $this->appender->append(new EventEnvelope(
            tenantId: $tenantId,
            threadId: $threadId,
            turnId: null,
            actorType: null !== $actorId ? ActorType::USER : ActorType::SYSTEM,
            actorId: $actorId,
            payload: new ThreadRenamed($title),
        ));
    }

    public function archive(Uuid $threadId, Uuid $tenantId, ?string $actorId = null): void
    {
        $this->appender->append(new EventEnvelope(
            tenantId: $tenantId,
            threadId: $threadId,
            turnId: null,
            actorType: null !== $actorId ? ActorType::USER : ActorType::SYSTEM,
            actorId: $actorId,
            payload: new ThreadArchived(),
        ));
    }

    /**
     * Set or clear the per-thread system-prompt override (step-03 chunk D9,
     * decision 5). A `null` prompt clears the override (the effective prompt
     * then falls back to the user's global default). Appends
     * `thread_system_prompt_set` (folds to `Thread::setSystemPrompt`).
     */
    public function setSystemPrompt(Uuid $threadId, Uuid $tenantId, ?string $systemPrompt, ?string $actorId = null): void
    {
        $this->appender->append(new EventEnvelope(
            tenantId: $tenantId,
            threadId: $threadId,
            turnId: null,
            actorType: null !== $actorId ? ActorType::USER : ActorType::SYSTEM,
            actorId: $actorId,
            payload: new ThreadSystemPromptSet($systemPrompt),
        ));
    }
}
