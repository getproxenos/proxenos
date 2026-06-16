<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Conversation\Event\EventEnvelope;
use App\Conversation\Event\Payload\ThreadEntityAttached;
use App\Conversation\Event\Payload\ThreadEntityDetached;
use App\Enum\ActorType;
use App\Repository\ThreadAttachmentRepository;
use App\TypedEntity\Reference;
use Symfony\Component\Uid\Uuid;

/**
 * Application service over the event-sourced attach/pin flow (step-02 chunk 6).
 * Writes go through `EventAppender` (canonical log → inline fold); reads come
 * from the `thread_attachments` projection.
 *
 * `tenantId` is threaded through every call because the `EventEnvelope` needs
 * it — an expected extension of the workplan's short signatures, not a conflict.
 */
final class ThreadAttachmentService
{
    public function __construct(
        private readonly EventAppender $appender,
        private readonly ThreadAttachmentRepository $attachments,
    ) {
    }

    public function attach(Uuid $threadId, Uuid $tenantId, Reference $reference, ?string $actorId = null): void
    {
        $this->appender->append(new EventEnvelope(
            tenantId: $tenantId,
            threadId: $threadId,
            turnId: null,
            actorType: null !== $actorId ? ActorType::USER : ActorType::SYSTEM,
            actorId: $actorId,
            payload: new ThreadEntityAttached($reference),
        ));
    }

    public function detach(
        Uuid $threadId,
        Uuid $tenantId,
        string $provider,
        string $type,
        string $id,
        ?string $actorId = null,
    ): void {
        $this->appender->append(new EventEnvelope(
            tenantId: $tenantId,
            threadId: $threadId,
            turnId: null,
            actorType: null !== $actorId ? ActorType::USER : ActorType::SYSTEM,
            actorId: $actorId,
            payload: new ThreadEntityDetached($provider, $type, $id),
        ));
    }

    /**
     * References attached to a thread, reconstructed from the projection in
     * attach order (`ThreadAttachmentService::listForThread` contract).
     *
     * @return list<Reference>
     */
    public function listForThread(Uuid $threadId): array
    {
        return array_map(
            static fn ($attachment): Reference => Reference::fromArray($attachment->getReference()),
            $this->attachments->findForThreadInAttachOrder($threadId),
        );
    }
}
