<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Membership;
use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;

/**
 * Shared helpers for SPA JSON API controllers. Using classes must declare
 * `CsrfTokenManagerInterface $csrf` and `MembershipRepository $memberships`
 * as constructor-injected properties, and must extend `AbstractController`.
 */
trait ApiControllerSupport
{
    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ('' === $raw) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->createAccessDeniedException('Invalid JSON body.');
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * CSRF protection shared with the SPA write surface. The 'chat' intention
     * covers every mutating SPA call during the Twig→SPA migration.
     */
    private function validateCsrf(Request $request): void
    {
        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->csrf->isTokenValid(new CsrfToken('chat', $token))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
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

    private function validationError(string $detail): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'validation_failed', 'detail' => $detail],
            Response::HTTP_BAD_REQUEST,
        );
    }
}
