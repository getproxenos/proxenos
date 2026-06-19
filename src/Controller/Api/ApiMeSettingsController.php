<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Thin per-user settings surface (step-03 chunk D9, decision 5). v0 holds a
 * single setting — the global default system prompt — read by
 * {@see \App\Ai\Chat\SystemPromptResolver} when a thread has no override.
 *
 *   GET /api/me/settings
 *   -> 200 {"system_prompt_default": "..." | null}
 *
 *   PUT /api/me/settings
 *     body: {"system_prompt_default": "..." | null}   header: X-CSRF-Token
 *   -> 200 {"system_prompt_default": "..." | null} — a null/blank value clears
 *      the default. Direct projection write on the `users` row (not
 *      event-sourced — `User` is identity state, not conversation state).
 *
 * Auth mirrors the other SPA API controllers: form_login session, ROLE_USER,
 * the shared 'chat' CSRF intention.
 */
#[Route('/api/me', name: 'api_me_')]
#[IsGranted('ROLE_USER')]
final class ApiMeSettingsController extends AbstractController
{
    use ApiControllerSupport;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/settings', name: 'settings_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        return new JsonResponse(['system_prompt_default' => $user->getSystemPromptDefault()]);
    }

    #[Route('/settings', name: 'settings_put', methods: ['PUT'])]
    public function put(Request $request): JsonResponse
    {
        $this->validateCsrf($request);

        $payload = $this->decodeJson($request);
        $raw = $payload['system_prompt_default'] ?? null;
        if (null !== $raw && !\is_string($raw)) {
            return $this->validationError('system_prompt_default must be a string or null.');
        }
        // Normalize at the boundary: a blank string clears the default.
        $value = (null === $raw || '' === trim($raw)) ? null : $raw;

        /** @var User $user */
        $user = $this->getUser();
        $user->setSystemPromptDefault($value);
        $this->em->flush();

        return new JsonResponse(['system_prompt_default' => $value]);
    }
}
