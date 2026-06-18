<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Conversation\Event\Payload\AssistantContentDelta;
use App\Conversation\Event\Payload\AssistantTurnCompleted;
use App\Conversation\Event\Payload\AssistantTurnFailed;
use App\Conversation\Event\Payload\ThreadRenamed;
use App\Conversation\Event\Payload\UserMessageSubmitted;
use App\Entity\ConversationEvent;
use App\Entity\Message;
use App\Entity\MessagePart;
use App\Entity\Thread;
use App\Entity\ThreadAttachment;
use App\Entity\Turn;
use App\Enum\ConversationEventType;
use App\Enum\MessageRole;
use App\Enum\MessageStatus;
use App\Repository\MessagePartRepository;
use App\Repository\MessageRepository;
use App\Repository\ThreadAttachmentRepository;
use App\Repository\ThreadRepository;
use App\Repository\TurnRepository;
use App\TypedEntity\Reference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Single source of fold truth — used by both the write path (`EventAppender`)
 * and the rebuild path (`app:projections:rebuild`). That symmetry is the
 * spec's guarantee ("projections are rebuildable") made operational
 * (ADR-022).
 *
 * Idempotency: every projection row carries `last_sequence`; folds that have
 * already been applied (`event.sequence <= row.last_sequence`) are skipped.
 * This makes a partial-rebuild safe to re-run.
 *
 * Each `apply()` flushes at the end so the next event in a rebuild sequence
 * sees up-to-date `MAX(position)` / find-by-turn queries.
 */
final class ProjectionFolder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ThreadRepository $threads,
        private readonly TurnRepository $turns,
        private readonly MessageRepository $messages,
        private readonly MessagePartRepository $parts,
        private readonly ThreadAttachmentRepository $attachments,
    ) {
    }

    public function apply(ConversationEvent $event): void
    {
        match ($event->getType()) {
            ConversationEventType::USER_MESSAGE_SUBMITTED => $this->foldUserMessageSubmitted($event),
            ConversationEventType::ASSISTANT_TURN_CREATED => $this->foldAssistantTurnCreated($event),
            ConversationEventType::ASSISTANT_CONTENT_DELTA => $this->foldAssistantContentDelta($event),
            ConversationEventType::ASSISTANT_TURN_COMPLETED => $this->foldAssistantTurnCompleted($event),
            ConversationEventType::ASSISTANT_TURN_FAILED => $this->foldAssistantTurnFailed($event),
            ConversationEventType::THREAD_ENTITY_ATTACHED => $this->foldThreadEntityAttached($event),
            ConversationEventType::THREAD_ENTITY_DETACHED => $this->foldThreadEntityDetached($event),
            ConversationEventType::THREAD_RENAMED => $this->foldThreadRenamed($event),
            ConversationEventType::THREAD_ARCHIVED => $this->foldThreadArchived($event),
        };

        $this->em->flush();
    }

    private function foldUserMessageSubmitted(ConversationEvent $event): void
    {
        $payload = new UserMessageSubmitted(
            Uuid::fromString((string) $event->getPayload()['message_id']),
            (string) $event->getPayload()['text'],
        );

        $thread = $this->ensureThread(
            $event->getThreadId(),
            $event->getTenantId(),
            null !== $event->getActorId() ? Uuid::fromString($event->getActorId()) : null,
            $event->getOccurredAt(),
        );

        if ($event->getSequence() <= $thread->getLastSequence()) {
            return;
        }

        $position = $this->messages->maxPositionForThread($event->getThreadId()) + 1;
        $message = new Message(
            $payload->messageId,
            $event->getThreadId(),
            null,
            $event->getTenantId(),
            MessageRole::USER,
            MessageStatus::COMPLETE,
            $position,
            $event->getSequence(),
            $event->getOccurredAt(),
            $event->getOccurredAt(),
        );
        $this->em->persist($message);

        $part = new MessagePart(
            Uuid::v7(),
            $payload->messageId,
            $event->getThreadId(),
            $event->getTenantId(),
            0,
            'text',
            $payload->text,
            $event->getOccurredAt(),
        );
        $this->em->persist($part);

        $thread->recordEvent($event->getSequence(), $event->getOccurredAt());
    }

    private function foldAssistantTurnCreated(ConversationEvent $event): void
    {
        $turnId = $event->getTurnId();
        if (null === $turnId) {
            throw new \LogicException('assistant_turn_created requires envelope.turn_id.');
        }

        $thread = $this->ensureThread(
            $event->getThreadId(),
            $event->getTenantId(),
            null,
            $event->getOccurredAt(),
        );

        if ($event->getSequence() <= $thread->getLastSequence() && null !== $this->turns->find($turnId)) {
            return;
        }

        if (null === $this->turns->find($turnId)) {
            $turn = new Turn(
                $turnId,
                $event->getThreadId(),
                $event->getTenantId(),
                $event->getSequence(),
                $event->getOccurredAt(),
            );
            $this->em->persist($turn);
        }

        $thread->recordEvent($event->getSequence(), $event->getOccurredAt());
    }

    private function foldAssistantContentDelta(ConversationEvent $event): void
    {
        $payload = new AssistantContentDelta(
            Uuid::fromString((string) $event->getPayload()['message_id']),
            (int) $event->getPayload()['part_index'],
            (string) $event->getPayload()['text'],
        );

        $turnId = $event->getTurnId();
        if (null === $turnId) {
            throw new \LogicException('assistant_content_delta requires envelope.turn_id.');
        }

        $thread = $this->ensureThread(
            $event->getThreadId(),
            $event->getTenantId(),
            null,
            $event->getOccurredAt(),
        );

        $turn = $this->turns->find($turnId);
        if (null === $turn) {
            throw new \LogicException(sprintf('assistant_content_delta references turn %s that has no projection row; replay assistant_turn_created first.', $turnId->toRfc4122()));
        }

        $message = $this->messages->findOneByTurnId($turnId);
        if (null === $message) {
            $position = $this->messages->maxPositionForThread($event->getThreadId()) + 1;
            $message = new Message(
                $payload->messageId,
                $event->getThreadId(),
                $turnId,
                $event->getTenantId(),
                MessageRole::ASSISTANT,
                MessageStatus::STREAMING,
                $position,
                $event->getSequence(),
                $event->getOccurredAt(),
            );
            $this->em->persist($message);
        } else {
            $message->bumpSequence($event->getSequence());
        }

        $part = $this->parts->findOneByMessageAndPosition($payload->messageId, $payload->partIndex);
        if (null === $part) {
            $part = new MessagePart(
                Uuid::v7(),
                $payload->messageId,
                $event->getThreadId(),
                $event->getTenantId(),
                $payload->partIndex,
                'text',
                $payload->text,
                $event->getOccurredAt(),
            );
            $this->em->persist($part);
        } else {
            $part->replaceContent($payload->text);
        }

        $turn->markStreaming($event->getSequence());
        $thread->recordEvent($event->getSequence(), $event->getOccurredAt());
    }

    private function foldAssistantTurnCompleted(ConversationEvent $event): void
    {
        $payload = new AssistantTurnCompleted(
            Uuid::fromString((string) $event->getPayload()['message_id']),
            (string) ($event->getPayload()['finish_reason'] ?? 'stop'),
        );

        $turnId = $event->getTurnId();
        if (null === $turnId) {
            throw new \LogicException('assistant_turn_completed requires envelope.turn_id.');
        }

        $turn = $this->turns->find($turnId);
        if (null === $turn) {
            throw new \LogicException(sprintf('assistant_turn_completed references turn %s with no projection row.', $turnId->toRfc4122()));
        }

        $message = $this->messages->find($payload->messageId);
        if (null === $message) {
            throw new \LogicException(sprintf('assistant_turn_completed references message %s with no projection row.', $payload->messageId->toRfc4122()));
        }

        $turn->markCompleted($event->getSequence(), $event->getOccurredAt());
        $message->markComplete($event->getSequence(), $event->getOccurredAt());

        $thread = $this->ensureThread(
            $event->getThreadId(),
            $event->getTenantId(),
            null,
            $event->getOccurredAt(),
        );
        $thread->recordEvent($event->getSequence(), $event->getOccurredAt());
    }

    private function foldAssistantTurnFailed(ConversationEvent $event): void
    {
        $rawMessageId = $event->getPayload()['message_id'] ?? null;
        $payload = new AssistantTurnFailed(
            null !== $rawMessageId ? Uuid::fromString((string) $rawMessageId) : null,
            (string) ($event->getPayload()['finish_reason'] ?? 'error'),
            (string) ($event->getPayload()['error_summary'] ?? ''),
        );

        $turnId = $event->getTurnId();
        if (null === $turnId) {
            throw new \LogicException('assistant_turn_failed requires envelope.turn_id.');
        }

        $turn = $this->turns->find($turnId);
        if (null === $turn) {
            throw new \LogicException(sprintf('assistant_turn_failed references turn %s with no projection row.', $turnId->toRfc4122()));
        }

        $turn->markFailed($event->getSequence(), $event->getOccurredAt());

        // The assistant Message row only exists if at least one content_delta
        // landed before the failure. Loop-level failures before any delta
        // (resolver / prompt-assembly throw) leave message_id null in the
        // payload — fold becomes a turn-only state change in that case.
        if (null !== $payload->messageId) {
            $message = $this->messages->find($payload->messageId);
            if (null !== $message) {
                $message->markFailed($event->getSequence(), $event->getOccurredAt());
            }
        }

        $thread = $this->ensureThread(
            $event->getThreadId(),
            $event->getTenantId(),
            null,
            $event->getOccurredAt(),
        );
        $thread->recordEvent($event->getSequence(), $event->getOccurredAt());
    }

    private function foldThreadEntityAttached(ConversationEvent $event): void
    {
        $payload = $event->getPayload();
        $referenceData = $payload['reference'] ?? null;
        if (!\is_array($referenceData)) {
            throw new \LogicException('thread_entity_attached requires a reference envelope in its payload.');
        }
        $reference = Reference::fromArray($referenceData);

        $attachedAt = isset($payload['attached_at']) && \is_string($payload['attached_at'])
            ? new \DateTimeImmutable($payload['attached_at'])
            : $event->getOccurredAt();

        $existing = $this->attachments->findOneByIdentity(
            $event->getThreadId(),
            $reference->provider,
            $reference->type,
            $reference->id,
        );

        if (null !== $existing) {
            // Idempotency cursor — already-applied folds are skipped so a
            // partial rebuild / double-replay is safe.
            if ($event->getSequence() <= $existing->getLastSequence()) {
                return;
            }
            $existing->reattach($reference->toArray(), $event->getSequence());

            return;
        }

        $attachment = new ThreadAttachment(
            $event->getThreadId(),
            $event->getTenantId(),
            $reference->provider,
            $reference->type,
            $reference->id,
            $reference->toArray(),
            $attachedAt,
            $event->getSequence(),
        );
        $this->em->persist($attachment);
    }

    private function foldThreadEntityDetached(ConversationEvent $event): void
    {
        $payload = $event->getPayload();
        $provider = (string) ($payload['provider'] ?? '');
        $type = (string) ($payload['type'] ?? '');
        $id = (string) ($payload['id'] ?? '');

        $existing = $this->attachments->findOneByIdentity($event->getThreadId(), $provider, $type, $id);
        // Detaching an absent row is a no-op — keeps a full replay
        // (attach…detach) and a double-replay idempotent.
        if (null === $existing) {
            return;
        }

        // Idempotency cursor — mirrors foldThreadEntityAttached. Guards
        // a future windowed/partial re-fold from removing a row that a
        // later attach already re-created.
        if ($event->getSequence() <= $existing->getLastSequence()) {
            return;
        }

        $this->em->remove($existing);
    }

    private function foldThreadRenamed(ConversationEvent $event): void
    {
        $payload = new ThreadRenamed((string) $event->getPayload()['title']);

        $thread = $this->ensureThread(
            $event->getThreadId(),
            $event->getTenantId(),
            null,
            $event->getOccurredAt(),
        );

        if ($event->getSequence() <= $thread->getLastSequence()) {
            return;
        }

        $thread->setTitle($payload->title);
        $thread->recordEvent($event->getSequence(), $event->getOccurredAt());
    }

    private function foldThreadArchived(ConversationEvent $event): void
    {
        $thread = $this->ensureThread(
            $event->getThreadId(),
            $event->getTenantId(),
            null,
            $event->getOccurredAt(),
        );

        if ($event->getSequence() <= $thread->getLastSequence()) {
            return;
        }

        $thread->markArchived();
        $thread->recordEvent($event->getSequence(), $event->getOccurredAt());
    }

    private function ensureThread(
        Uuid $threadId,
        Uuid $tenantId,
        ?Uuid $createdByUserId,
        \DateTimeImmutable $createdAt,
    ): Thread {
        $thread = $this->threads->find($threadId);
        if (null !== $thread) {
            return $thread;
        }

        $thread = new Thread($threadId, $tenantId, $createdByUserId, $createdAt);
        $this->em->persist($thread);

        return $thread;
    }
}
