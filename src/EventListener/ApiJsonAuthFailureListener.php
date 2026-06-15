<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * SPA API auth contract (ADR-026, handoff §3): unauthenticated calls to
 * `/api/*` return JSON 401; cross-tenant calls return JSON 403. Without
 * this, the `form_login` firewall would 302 anonymous requests to /login
 * (HTML) — fine for Twig, hostile for the SPA's `fetch`.
 *
 * Implemented as a kernel.exception listener so the firewall stays
 * unchanged; the redirect path remains the source of truth for browser
 * navigation, and only XHR-shaped paths under `/api` see the JSON
 * envelope.
 */
#[AsEventListener(event: ExceptionEvent::class, priority: 8)]
final class ApiJsonAuthFailureListener
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/') && '/api' !== $path) {
            return;
        }

        $exception = $event->getThrowable();

        // AuthenticationException (and the InsufficientAuthenticationException
        // subclass the firewall raises for anonymous users) → 401.
        if ($exception instanceof AuthenticationException) {
            $event->setResponse($this->unauthenticated());

            return;
        }

        // AccessDeniedException semantics depend on whether the caller is
        // logged in: anonymous → 401 (the SPA redirects to /login); logged-in
        // → 403 (the SPA renders a "not allowed" surface). Without this split
        // an anonymous request to a tenant-scoped route looks like a
        // permission error rather than a missing session.
        if ($exception instanceof AccessDeniedException) {
            $event->setResponse(
                null === $this->security->getUser() ? $this->unauthenticated() : $this->forbidden(),
            );

            return;
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            if (JsonResponse::HTTP_UNAUTHORIZED === $status) {
                $event->setResponse($this->unauthenticated());
            } elseif (JsonResponse::HTTP_FORBIDDEN === $status) {
                $event->setResponse($this->forbidden());
            }
        }
    }

    private function unauthenticated(): JsonResponse
    {
        return new JsonResponse(['error' => 'unauthenticated'], JsonResponse::HTTP_UNAUTHORIZED);
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['error' => 'forbidden'], JsonResponse::HTTP_FORBIDDEN);
    }
}
