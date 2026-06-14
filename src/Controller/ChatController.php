<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\Chat\ChatRespondLoop;
use App\Ai\Chat\ChatRespondRequest;
use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MessageRole;
use App\Repository\MembershipRepository;
use App\Repository\MessagePartRepository;
use App\Repository\MessageRepository;
use App\Repository\ThreadRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 0.3 minimal Twig chat UI (handoff §"Minimal web UI"). Twig over SPA
 * (ADR-023) because Phase 0.1 deferred SPA auth wiring; threading SPA auth
 * into the same PR that introduces the loop would explode scope.
 *
 * Routing model: thread-id is in the path; rendering re-reads the projection
 * each time. The Twig view is intentionally read-only for events — the only
 * write path is POSTing a new user message into {@see ChatRespondLoop}, which
 * appends events through the same EventAppender used by app:projections:rebuild.
 */
#[Route('/chat', name: 'chat_')]
#[IsGranted('ROLE_USER')]
final class ChatController extends AbstractController
{
    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly ThreadRepository $threads,
        private readonly MessageRepository $messages,
        private readonly MessagePartRepository $parts,
        private readonly ChatRespondLoop $loop,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $membership = $this->resolveMembershipOrAbort();
        $threads = $this->threads->findByTenantOrderedByUpdatedAt($membership->getTenant()->getId());

        if ([] !== $threads) {
            return $this->redirectToRoute('chat_show', ['threadId' => (string) $threads[0]->getId()]);
        }

        return $this->redirectToRoute('chat_new');
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(): RedirectResponse
    {
        $this->resolveMembershipOrAbort();
        $threadId = Uuid::v7();

        return $this->redirectToRoute('chat_show', ['threadId' => (string) $threadId]);
    }

    #[Route('/{threadId}', name: 'show', methods: ['GET'], requirements: ['threadId' => '[0-9a-f-]{36}'])]
    public function show(string $threadId): Response
    {
        $membership = $this->resolveMembershipOrAbort();
        $threadUuid = Uuid::fromString($threadId);

        // A freshly /new thread has no projection row yet — render an empty view.
        // Once a real thread exists, enforce tenant scoping.
        $thread = $this->threads->find($threadUuid);
        if (null !== $thread && !$thread->getTenantId()->equals($membership->getTenant()->getId())) {
            throw $this->createAccessDeniedException('Thread does not belong to current tenant.');
        }

        $messages = null !== $thread ? $this->messages->findByThreadOrdered($threadUuid) : [];

        $rendered = [];
        foreach ($messages as $message) {
            $parts = $this->parts->findByMessageOrdered($message->getId());
            $text = '';
            foreach ($parts as $part) {
                if ('text' === $part->getKind()) {
                    $text .= $part->getContent();
                }
            }
            $rendered[] = [
                'role' => $message->getRole()->value,
                'is_user' => MessageRole::USER === $message->getRole(),
                'text' => $text,
                'status' => $message->getStatus()->value,
                'created_at' => $message->getCreatedAt(),
            ];
        }

        $threadList = $this->threads->findByTenantOrderedByUpdatedAt($membership->getTenant()->getId());

        return $this->render('chat/show.html.twig', [
            'thread_id' => $threadId,
            'thread_exists' => null !== $thread,
            'messages' => $rendered,
            'thread_list' => $threadList,
        ]);
    }

    #[Route('/{threadId}/messages', name: 'submit', methods: ['POST'], requirements: ['threadId' => '[0-9a-f-]{36}'])]
    public function submit(string $threadId, Request $request): Response
    {
        $membership = $this->resolveMembershipOrAbort();
        $threadUuid = Uuid::fromString($threadId);

        $token = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('chat', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $existing = $this->threads->find($threadUuid);
        if (null !== $existing && !$existing->getTenantId()->equals($membership->getTenant()->getId())) {
            throw $this->createAccessDeniedException('Thread does not belong to current tenant.');
        }

        $text = trim((string) $request->request->get('text', ''));
        if ('' === $text) {
            $this->addFlash('error', 'Message text cannot be empty.');

            return $this->redirectToRoute('chat_show', ['threadId' => $threadId]);
        }

        /** @var User $user */
        $user = $this->getUser();

        $this->loop->execute(new ChatRespondRequest(
            tenantId: $membership->getTenant()->getId(),
            userId: $user->getId(),
            threadId: $threadUuid,
            userMessageText: $text,
        ));

        return $this->redirectToRoute('chat_show', ['threadId' => $threadId]);
    }

    /**
     * SSE companion to {@see self::submit()}. Same CSRF + tenancy checks; same
     * `ChatRespondLoop` call. The difference is that this endpoint streams
     * cumulative-text deltas back as `text/event-stream` events while the loop
     * runs, so the Twig thread view can render text progressively.
     *
     * Deliberately dumb: no cursor, no replay, no reconnect — those belong to
     * the deferred assistant-ui SPA (`design-notes/streaming-runtime-notes.md`
     * §3). On reconnect mid-stream, the client just reloads the thread page;
     * the durable event log already has every persisted delta.
     */
    #[Route('/{threadId}/messages/stream', name: 'submit_stream', methods: ['POST'], requirements: ['threadId' => '[0-9a-f-]{36}'])]
    public function submitStream(string $threadId, Request $request): Response
    {
        $membership = $this->resolveMembershipOrAbort();
        $threadUuid = Uuid::fromString($threadId);

        $token = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid('chat', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $existing = $this->threads->find($threadUuid);
        if (null !== $existing && !$existing->getTenantId()->equals($membership->getTenant()->getId())) {
            throw $this->createAccessDeniedException('Thread does not belong to current tenant.');
        }

        $text = trim((string) $request->request->get('text', ''));
        if ('' === $text) {
            return new Response('Message text cannot be empty.', Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        $tenantId = $membership->getTenant()->getId();
        $userId = $user->getId();

        $response = new StreamedResponse(function () use ($tenantId, $userId, $threadUuid, $text): void {
            $emit = static function (string $type, array $data): void {
                echo 'data: '.json_encode(['type' => $type] + $data, \JSON_THROW_ON_ERROR)."\n\n";
                // Guard ob_flush(): it warns when no PHP output buffer is active
                // (the common case for a Symfony StreamedResponse), and @ would
                // mask any real flush error.
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                $result = $this->loop->execute(new ChatRespondRequest(
                    tenantId: $tenantId,
                    userId: $userId,
                    threadId: $threadUuid,
                    userMessageText: $text,
                    onDelta: static function (string $cumulative) use ($emit): void {
                        $emit('delta', ['text' => $cumulative]);
                    },
                ));
                $emit('done', [
                    'thread_id' => (string) $result->threadId,
                    'turn_id' => (string) $result->turnId,
                    'text' => $result->assistantText,
                ]);
            } catch (\Throwable $e) {
                $emit('error', ['message' => $e->getMessage()]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no'); // disable nginx/Caddy proxy buffering

        return $response;
    }

    private function resolveMembershipOrAbort(): Membership
    {
        /** @var User $user */
        $user = $this->getUser();
        $membership = $this->memberships->findOneForUser($user);
        if (null === $membership) {
            throw $this->createAccessDeniedException('User has no tenant membership; mint one with app:tenant:create + app:user:create.');
        }

        return $membership;
    }
}
