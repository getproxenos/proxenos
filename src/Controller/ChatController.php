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
