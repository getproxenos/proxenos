<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ConversationEvent;
use App\Entity\Membership;
use App\Entity\User;
use App\Repository\ConversationEventRepository;
use App\Repository\MembershipRepository;
use App\Repository\ThreadRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * SPA cursor-based event replay endpoint (handoff §1, ADR-024 point 4,
 * ADR-026, `design-notes/streaming-runtime-notes.md` §5).
 *
 * The event log already has monotonic `(thread_id, sequence)`; this just
 * exposes it. The store uses it for replay-after-reconnect, hidden-tab
 * resume, and live-vs-replay reconciliation. Live deliveries (Mercure) and
 * replay rows are normalized through the same envelope shape so the SPA
 * folds them through one reducer.
 *
 * Auth: same `form_login` session as the Twig chat (ADR-026, handoff §3).
 * Tenancy: the thread must belong to the caller's tenant; cross-tenant
 * access is 403. A nonexistent thread is 404 (we cannot tenant-check what
 * doesn't exist; the cursor endpoint stays honest about that and returns
 * 404 rather than leaking existence via 403). Auth-failure under the
 * `form_login` firewall is the normal 302 to /login the rest of the app
 * uses — JSON 401 is a follow-up when the SPA is the default entry point.
 */
#[Route('/api/threads/{threadId}', name: 'api_threads_', requirements: ['threadId' => '[0-9a-f-]{36}'])]
#[IsGranted('ROLE_USER')]
final class ThreadEventsController extends AbstractController
{
    /**
     * Maximum events returned per page. Prevents a chatty client from
     * pulling the entire log in one shot; `has_more=true` plus `next_after`
     * lets the SPA paginate.
     */
    private const int MAX_LIMIT = 500;

    /**
     * Default page size when the client does not pass `limit`. Picked to
     * hold a typical short thread (user + assistant turn with coalesced
     * deltas) in one page without round-trip churn.
     */
    private const int DEFAULT_LIMIT = 200;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly ThreadRepository $threads,
        private readonly ConversationEventRepository $events,
    ) {
    }

    #[Route('/events', name: 'events', methods: ['GET'])]
    public function events(string $threadId, Request $request): JsonResponse
    {
        $membership = $this->resolveMembershipOrAbort();
        $threadUuid = Uuid::fromString($threadId);

        $thread = $this->threads->find($threadUuid);
        if (null === $thread) {
            return $this->json(['error' => 'thread_not_found'], Response::HTTP_NOT_FOUND);
        }
        if (!$thread->getTenantId()->equals($membership->getTenant()->getId())) {
            throw $this->createAccessDeniedException('Thread does not belong to current tenant.');
        }

        $after = $this->parseAfter($request);
        $limit = $this->parseLimit($request);

        // Pull `limit + 1` so we can detect `has_more` without a separate
        // COUNT. The last row stays in the result set only if we kept it
        // (slice back to `limit`).
        $rows = $this->events->findByThreadAfterSequence($threadUuid, $after, $limit + 1);
        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            $rows = \array_slice($rows, 0, $limit);
        }

        $serialized = array_map(fn (ConversationEvent $e) => $this->serializeEvent($e), $rows);
        // next_after is the cursor for the FOLLOW-UP call; null when there
        // is no follow-up (either the page is empty, or has_more is false).
        // Live updates after this page arrive over Mercure; the SPA only
        // needs to call replay again on reconnect.
        $nextAfter = $hasMore ? $rows[\array_key_last($rows)]->getSequence() : null;

        return $this->json([
            'events' => $serialized,
            'next_after' => $nextAfter,
            'has_more' => $hasMore,
        ]);
    }

    private function parseAfter(Request $request): int
    {
        $raw = $request->query->get('after', '0');
        if (!is_numeric($raw)) {
            return 0;
        }
        $value = (int) $raw;

        return max(0, $value);
    }

    private function parseLimit(Request $request): int
    {
        $raw = $request->query->get('limit');
        if (null === $raw || !is_numeric($raw)) {
            return self::DEFAULT_LIMIT;
        }
        $value = (int) $raw;
        if ($value < 1) {
            return self::DEFAULT_LIMIT;
        }

        return min(self::MAX_LIMIT, $value);
    }

    /**
     * Normalize a stored event to the same envelope the live transport
     * publishes (ADR-026, handoff §1). The shape MUST match what the
     * Mercure publisher emits — the SPA reducer expects a single union.
     *
     * @return array<string, mixed>
     */
    private function serializeEvent(ConversationEvent $event): array
    {
        return [
            'id' => $event->getId()->toRfc4122(),
            'sequence' => $event->getSequence(),
            'thread_id' => $event->getThreadId()->toRfc4122(),
            'turn_id' => $event->getTurnId()?->toRfc4122(),
            'type' => $event->getType()->value,
            'version' => $event->getVersion(),
            'actor_type' => $event->getActorType()->value,
            'actor_id' => $event->getActorId(),
            'occurred_at' => $event->getOccurredAt()->format(\DateTimeInterface::RFC3339),
            'payload' => $event->getPayload(),
        ];
    }

    private function resolveMembershipOrAbort(): Membership
    {
        /** @var User $user */
        $user = $this->getUser();
        $membership = $this->memberships->findOneForUser($user);
        if (null === $membership) {
            throw $this->createAccessDeniedException('User has no tenant membership.');
        }

        return $membership;
    }
}
