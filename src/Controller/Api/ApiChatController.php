<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Ai\Chat\ChatRespondLoop;
use App\Ai\Chat\ChatRespondRequest;
use App\Ai\Chat\TurnCancellation;
use App\Conversation\Title\ThreadAutoTitler;
use App\Entity\User;
use App\Repository\MembershipRepository;
use App\Repository\ThreadRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * SPA write surface (handoff §4 interface contract). Mirrors the Twig
 * chat submit + cancel endpoints in JSON shape so the
 * `ExternalStoreRuntime` adapter can call them from the browser:
 *
 *   POST /api/threads/{id}/messages
 *     body: {"text": "..."}
 *     header: X-CSRF-Token
 *   -> runs ChatRespondLoop; live deltas flow over Mercure (handoff §2).
 *      Returns 202 with the submitted turn metadata; the SPA renders
 *      from the Mercure stream + cursor replay, not from this body.
 *
 *   POST /api/threads/{id}/runs/{turnId}/cancel
 *     header: X-CSRF-Token
 *   -> records the cooperative-cancel request in the cross-request
 *      TurnCancellation store (step-03 chunk D7). The concurrently
 *      streaming ChatRespondLoop polls it on each coalesced flush, stops
 *      draining, and appends the terminal `assistant_turn_cancelled`
 *      event. Returns 202; the SPA tolerates a "cancel requested, no
 *      terminal event yet" state by design (handoff §4 reconciliation
 *      case 5).
 *
 * Auth: same form_login session as Twig (handoff §3); CSRF token is
 * the 'chat' intention so the same token works for both surfaces
 * during the Twig→SPA migration.
 */
#[Route('/api/threads/{threadId}', name: 'api_chat_', requirements: ['threadId' => '[0-9a-f-]{36}'])]
#[IsGranted('ROLE_USER')]
final class ApiChatController extends AbstractController
{
    use ApiControllerSupport;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly ThreadRepository $threads,
        private readonly ChatRespondLoop $loop,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly ThreadAutoTitler $autoTitler,
        private readonly TurnCancellation $cancellation,
    ) {
    }

    #[Route('/messages', name: 'submit', methods: ['POST'])]
    public function submit(string $threadId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $membership = $this->resolveMembershipOrAbort();
        $threadUuid = Uuid::fromString($threadId);

        $existing = $this->threads->find($threadUuid);
        if (null !== $existing && !$existing->getTenantId()->equals($membership->getTenant()->getId())) {
            throw $this->createAccessDeniedException('Thread does not belong to current tenant.');
        }

        $payload = $this->decodeJson($request);
        $text = trim((string) ($payload['text'] ?? ''));
        if ('' === $text) {
            return new JsonResponse(['error' => 'text_required'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();

        // Gate the auto-titler on state captured BEFORE the loop runs: a thread
        // with no row, or a row that was never titled, is "new" for this turn.
        // After the first submit auto-titles it, the projected title is set, so
        // a second message sees a title and the titler is skipped — once per
        // thread (step-03 chunk D4).
        $isNewlyTitleableThread = null === $existing || null === $existing->getTitle();

        $result = $this->loop->execute(new ChatRespondRequest(
            tenantId: $membership->getTenant()->getId(),
            userId: $user->getId(),
            threadId: $threadUuid,
            userMessageText: $text,
        ));

        // D4: auto-title a freshly-created thread from its first user message.
        // Runs AFTER the loop (the loop materialized the thread + folded its
        // events) and stays out of ChatRespondLoop so the core loop is untouched.
        if ($isNewlyTitleableThread) {
            $this->autoTitler->autoTitle($threadUuid, $membership->getTenant()->getId(), $text);
        }

        // 202: the SPA already saw deltas via Mercure; this response
        // confirms the turn boundaries. Body is metadata, not the
        // rendered content — the SPA folds events, not response JSON.
        return new JsonResponse([
            'thread_id' => (string) $result->threadId,
            'turn_id' => (string) $result->turnId,
            'assistant_message_id' => (string) $result->assistantMessageId,
        ], Response::HTTP_ACCEPTED);
    }

    #[Route('/runs/{turnId}/cancel', name: 'cancel', methods: ['POST'], requirements: ['turnId' => '[0-9a-f-]{36}'])]
    public function cancel(string $threadId, string $turnId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $membership = $this->resolveMembershipOrAbort();
        $threadUuid = Uuid::fromString($threadId);

        $existing = $this->threads->find($threadUuid);
        if (null !== $existing && !$existing->getTenantId()->equals($membership->getTenant()->getId())) {
            throw $this->createAccessDeniedException('Thread does not belong to current tenant.');
        }

        // Cooperative cancel (step-03 chunk D7, decision 4): record the
        // request in the cross-request TurnCancellation store. The concurrently
        // streaming ChatRespondLoop polls this on each coalesced flush, stops
        // draining, and appends the terminal `assistant_turn_cancelled` event
        // itself. The SPA's reconciliation case 5 ("cancel requested, no
        // terminal event yet") holds the cancelling state until that event
        // arrives — this endpoint only sets the signal and returns 202.
        $this->cancellation->request(Uuid::fromString($turnId));

        return new JsonResponse([
            'thread_id' => $threadId,
            'turn_id' => $turnId,
            'status' => 'cancel_requested',
        ], Response::HTTP_ACCEPTED);
    }
}
