<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Conversation\ThreadLifecycleService;
use App\Entity\Membership;
use App\Entity\Thread;
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
 * SPA thread-lifecycle surface (step-03 chunk D2). The read + rename/archive
 * write endpoints the thread list (D3) is built on:
 *
 *   GET /api/threads
 *   -> 200 [{ id, title, status, updated_at }] — the caller tenant's ACTIVE
 *      threads, most recently active first. Archived threads are soft-hidden
 *      (decision 10); their history stays in the event log.
 *
 *   POST /api/threads/{threadId}/rename
 *     body: {"title": "..."}   header: X-CSRF-Token
 *   -> 202 {"status": "thread_renamed"} — emits thread_renamed. Empty/over-long
 *      title -> 400.
 *
 *   POST /api/threads/{threadId}/archive
 *     header: X-CSRF-Token
 *   -> 202 {"status": "thread_archived"} — emits thread_archived (soft hide).
 *
 * Auth mirrors {@see ApiThreadAttachmentController} exactly: form_login
 * session, ROLE_USER, the shared 'chat' CSRF intention, and a
 * thread-belongs-to-tenant guard so one tenant can never mutate another
 * tenant's thread.
 */
#[Route('/api/threads', name: 'api_thread_')]
#[IsGranted('ROLE_USER')]
final class ApiThreadController extends AbstractController
{
    use ApiControllerSupport;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly ThreadRepository $threads,
        private readonly ThreadLifecycleService $lifecycle,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $membership = $this->resolveMembershipOrAbort();

        $out = array_map(
            static fn (Thread $thread): array => [
                'id' => $thread->getId()->toRfc4122(),
                'title' => $thread->getTitle(),
                'status' => $thread->getStatus(),
                'updated_at' => $thread->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $this->threads->findActiveByTenantOrderedByUpdatedAt($membership->getTenant()->getId()),
        );

        return new JsonResponse($out);
    }

    #[Route('/{threadId}/rename', name: 'rename', methods: ['POST'], requirements: ['threadId' => '[0-9a-f-]{36}'])]
    public function rename(string $threadId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $membership = $this->resolveMembershipOrAbort();
        $threadUuid = $this->resolveThreadOrAbort($threadId, $membership);

        $payload = $this->decodeJson($request);
        $title = $payload['title'] ?? null;
        if (!\is_string($title) || '' === trim($title)) {
            return $this->validationError('title is required and must be a non-empty string.');
        }
        // The projection's title column is varchar(200); reject over-long titles
        // here rather than letting the fold blow up on a DB constraint.
        if (mb_strlen($title) > 200) {
            return $this->validationError('title must be at most 200 characters.');
        }

        /** @var User $user */
        $user = $this->getUser();

        $this->lifecycle->rename(
            $threadUuid,
            $membership->getTenant()->getId(),
            $title,
            $user->getId()->toRfc4122(),
        );

        return new JsonResponse(['status' => 'thread_renamed'], Response::HTTP_ACCEPTED);
    }

    #[Route('/{threadId}/archive', name: 'archive', methods: ['POST'], requirements: ['threadId' => '[0-9a-f-]{36}'])]
    public function archive(string $threadId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $membership = $this->resolveMembershipOrAbort();
        $threadUuid = $this->resolveThreadOrAbort($threadId, $membership);

        /** @var User $user */
        $user = $this->getUser();

        $this->lifecycle->archive(
            $threadUuid,
            $membership->getTenant()->getId(),
            $user->getId()->toRfc4122(),
        );

        return new JsonResponse(['status' => 'thread_archived'], Response::HTTP_ACCEPTED);
    }

    private function resolveThreadOrAbort(string $threadId, Membership $membership): Uuid
    {
        if (!Uuid::isValid($threadId)) {
            throw $this->createNotFoundException('Thread not found.');
        }

        $threadUuid = Uuid::fromString($threadId);

        $existing = $this->threads->find($threadUuid);
        if (null !== $existing && !$existing->getTenantId()->equals($membership->getTenant()->getId())) {
            throw $this->createAccessDeniedException('Thread does not belong to current tenant.');
        }

        return $threadUuid;
    }
}
