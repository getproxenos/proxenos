<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\ConversationEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Fans an appended {@see ConversationEvent} out to subscribers via Mercure
 * (handoff §2, ADR-026). The event log remains canonical — this is push
 * only, used by the SPA's `ExternalStoreRuntime` adapter for live deltas.
 * Reconnect / replay rides on the cursor endpoint instead.
 *
 * Degradation contract: when MERCURE_URL is unset (the `make test` case,
 * or any environment that hasn't stood up a hub), `$hub` is null and this
 * service is a no-op. The host stays operable; the SPA loses live push
 * but cursor replay still hydrates a thread on next poll/load. This mirrors
 * the LLM_BASE_URL "works when unset" pattern.
 *
 * Failure contract: a hub publish failure must NEVER fail the append. The
 * canonical write already committed; bubbling the publish error would
 * revert nothing and would leave the caller with a half-success story.
 * We log and swallow — the SPA will catch up on next reconnect via the
 * replay endpoint.
 *
 * Coalescing: the loop already coalesces `assistant_content_delta`s in
 * {@see \App\Ai\Chat\ChatRespondLoop}; publishing one Mercure update per
 * appended row preserves that cadence one-for-one. No additional batching.
 */
final class ConversationEventPublisher
{
    public function __construct(
        private readonly ConversationEventEnvelope $envelope,
        private readonly ?HubInterface $hub = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function publish(ConversationEvent $event): void
    {
        if (null === $this->hub) {
            return;
        }

        $topic = $this->envelope->topicForThread($event->getThreadId()->toRfc4122());
        $payload = json_encode($this->envelope->toArray($event), \JSON_THROW_ON_ERROR);

        $update = new Update($topic, $payload, private: true);

        try {
            $this->hub->publish($update);
        } catch (\Throwable $e) {
            // Best-effort push: the durable log already has this event. The
            // SPA will see it on next replay/reconnect.
            $this->logger->warning('mercure: failed to publish conversation event', [
                'event_id' => $event->getId()->toRfc4122(),
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
