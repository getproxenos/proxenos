<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Conversation\ConversationEventEnvelope;
use App\Entity\Membership;
use App\Entity\User;
use App\Repository\MembershipRepository;
use App\Repository\ThreadRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * SPA bootstrap (handoff §3, ADR-026). One round-trip handing the React
 * SPA everything it needs to start a session over the existing form_login
 * cookie: identity, the CSRF token mutating fetches will echo, the
 * Mercure hub URL, and a freshly-minted subscriber JWT.
 *
 * Auth shape (ADR-026 lean): same-origin session cookie. No new token
 * surface. The Mercure publish key NEVER leaves the server — only a
 * scoped, short-lived SUBSCRIBE token reaches the browser.
 *
 * Subscribe scope: enumerates the caller's tenant's threads and emits
 * one subscribe entry per thread (`/threads/{id}/events`). New threads
 * created during a session require re-fetching this endpoint — fine for
 * v0 (the SPA refreshes on `onNew`). A wildcard `/threads/<asterisk>/events`
 * scope was rejected: it would let any user of the system subscribe to
 * any thread by UUID, and the handoff §3 contract says "scoped to the
 * threads the authenticated user can read in the current tenant".
 *
 * The mercureAuthorization cookie is set HttpOnly + SameSite=strict so
 * the SPA cannot read it, only the EventSource handshake to the Mercure
 * hub does. Lifetime mirrors the JWT exp.
 */
#[Route('/api', name: 'api_')]
#[IsGranted('ROLE_USER')]
final class SpaBootstrapController extends AbstractController
{
    /**
     * Mercure subscriber JWT lifetime (seconds). Short by design — the
     * SPA re-fetches this endpoint to refresh. Long enough to cover a
     * typical session, short enough to bound exposure if the cookie
     * leaks.
     */
    private const int MERCURE_TOKEN_TTL = 3600;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly ThreadRepository $threads,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly HubInterface $hub,
        private readonly Authorization $mercureAuthorization,
        private readonly ConversationEventEnvelope $envelope,
    ) {
    }

    #[Route('/me/bootstrap', name: 'me_bootstrap', methods: ['GET'])]
    public function bootstrap(Request $request): JsonResponse
    {
        $membership = $this->resolveMembershipOrAbort();
        /** @var User $user */
        $user = $this->getUser();

        $threads = $this->threads->findByTenantOrderedByUpdatedAt(
            $membership->getTenant()->getId(),
        );
        $subscribeTopics = array_map(
            fn ($thread) => $this->envelope->topicForThread($thread->getId()->toRfc4122()),
            $threads,
        );

        // The bundle's Authorization helper mints the JWT, derives cookie
        // path/domain/secure flags from the hub URL, and registers the
        // cookie on the request — SetCookieSubscriber attaches it to the
        // response in kernel.response. Publish claim stays empty: the
        // server is the only publisher.
        $this->mercureAuthorization->setCookie(
            $request,
            subscribe: $subscribeTopics,
            publish: [],
            additionalClaims: ['exp' => new \DateTimeImmutable('+'.self::MERCURE_TOKEN_TTL.' seconds')],
        );

        $response = new JsonResponse([
            'user' => [
                'id' => $user->getId()->toRfc4122(),
                'email' => $user->getUserIdentifier(),
            ],
            'tenant' => [
                'id' => $membership->getTenant()->getId()->toRfc4122(),
                'slug' => $membership->getTenant()->getSlug(),
                'name' => $membership->getTenant()->getName(),
            ],
            'csrf_token' => $this->csrf->getToken('chat')->getValue(),
            'mercure' => [
                'hub_url' => $this->hub->getPublicUrl(),
                // Per-thread topic format (handoff §2). Live + replay
                // resolve through the same coordinate.
                'topic_template' => '/threads/{threadId}/events',
                'subscribed_topics' => $subscribeTopics,
            ],
        ]);

        return $response;
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
