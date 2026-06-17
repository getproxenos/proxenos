<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Conversation\ThreadAttachmentService;
use App\Entity\Membership;
use App\Entity\User;
use App\Repository\MembershipRepository;
use App\Repository\ThreadRepository;
use App\TypedEntity\Reference;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * SPA attach/pin write surface (step-02 chunk 8). Mints the two event-sourced
 * attach/detach events on a thread's conversation log via
 * {@see ThreadAttachmentService}:
 *
 *   POST /api/threads/{threadId}/attachments
 *     body: a Reference envelope OR the identity triple
 *           {"provider": "...", "type": "...", "id": "...",
 *            "expansion"?: "pill"|"summary"|"full", "label"?, "marker"?,
 *            "snapshot"?}
 *     header: X-CSRF-Token
 *   -> 201 {"attached": <reference envelope>} — emits thread_entity_attached.
 *      Missing provider/type/id -> 400.
 *
 *   DELETE /api/threads/{threadId}/attachments
 *     identity triple from the JSON body OR query params
 *           {"provider": "...", "type": "...", "id": "..."}
 *     header: X-CSRF-Token
 *   -> 204 — emits thread_entity_detached. Detach of a non-attached triple
 *      is a no-op (the chunk-6 fold tolerates it), so this is idempotent and
 *      never 404s. Missing provider/type/id -> 400.
 *
 * Auth mirrors {@see ApiChatController} exactly: form_login session,
 * ROLE_USER, the shared 'chat' CSRF intention, and a thread-belongs-to-tenant
 * guard so one tenant can never pin onto another tenant's thread.
 */
#[Route('/api/threads/{threadId}/attachments', name: 'api_thread_attachment_', requirements: ['threadId' => '[0-9a-f-]{36}'])]
#[IsGranted('ROLE_USER')]
final class ApiThreadAttachmentController extends AbstractController
{
    use ApiControllerSupport;

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly ThreadRepository $threads,
        private readonly ThreadAttachmentService $attachments,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('', name: 'attach', methods: ['POST'])]
    public function attach(string $threadId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $membership = $this->resolveMembershipOrAbort();
        $threadUuid = $this->resolveThreadOrAbort($threadId, $membership);

        $payload = $this->decodeJson($request);

        $triple = $this->identityTripleOrNull($payload);
        if (null === $triple) {
            return $this->validationError('provider, type, and id are required and must be non-empty strings.');
        }

        try {
            $reference = $this->buildReference($payload, $triple);
        } catch (\InvalidArgumentException $e) {
            return $this->validationError($e->getMessage());
        }

        /** @var User $user */
        $user = $this->getUser();

        $this->attachments->attach(
            $threadUuid,
            $membership->getTenant()->getId(),
            $reference,
            $user->getId()->toRfc4122(),
        );

        return new JsonResponse(['attached' => $reference->toArray()], Response::HTTP_CREATED);
    }

    #[Route('', name: 'detach', methods: ['DELETE'])]
    public function detach(string $threadId, Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $membership = $this->resolveMembershipOrAbort();
        $threadUuid = $this->resolveThreadOrAbort($threadId, $membership);

        $payload = $this->decodeJson($request);
        // Identity triple may arrive in the JSON body or as query params (DELETE
        // bodies are awkward for some clients), so fall back to the query.
        $source = [
            'provider' => $payload['provider'] ?? $request->query->get('provider'),
            'type' => $payload['type'] ?? $request->query->get('type'),
            'id' => $payload['id'] ?? $request->query->get('id'),
        ];

        $triple = $this->identityTripleOrNull($source);
        if (null === $triple) {
            return $this->validationError('provider, type, and id are required and must be non-empty strings.');
        }

        /** @var User $user */
        $user = $this->getUser();

        // Detach is idempotent per the chunk-6 fold: detaching a triple that was
        // never attached is a no-op, NOT a 404. We always report success.
        $this->attachments->detach(
            $threadUuid,
            $membership->getTenant()->getId(),
            $triple['provider'],
            $triple['type'],
            $triple['id'],
            $user->getId()->toRfc4122(),
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Validate the identity triple (provider/type/id) and return it as
     * non-empty strings, or null if any is missing/blank/non-string.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{provider: string, type: string, id: string}|null
     */
    private function identityTripleOrNull(array $payload): ?array
    {
        $provider = $payload['provider'] ?? null;
        $type = $payload['type'] ?? null;
        $id = $payload['id'] ?? null;

        if (!\is_string($provider) || '' === trim($provider)) {
            return null;
        }
        if (!\is_string($type) || '' === trim($type)) {
            return null;
        }
        if (!\is_string($id) || '' === trim($id)) {
            return null;
        }

        return ['provider' => $provider, 'type' => $type, 'id' => $id];
    }

    /**
     * Build the {@see Reference} VO from the request body, honoring the optional
     * envelope fields. Expansion defaults to `pill` per the chunk-8 decision.
     *
     * @param array<string, mixed>                                $payload
     * @param array{provider: string, type: string, id: string} $triple
     */
    private function buildReference(array $payload, array $triple): Reference
    {
        $data = $triple;
        $data['expansion'] = $payload['expansion'] ?? Reference::EXPANSION_PILL;
        if (\array_key_exists('resolved', $payload)) {
            $data['resolved'] = $payload['resolved'];
        }
        if (\array_key_exists('marker', $payload)) {
            $data['marker'] = $payload['marker'];
        }
        if (\array_key_exists('label', $payload)) {
            $data['label'] = $payload['label'];
        }
        if (\array_key_exists('snapshot', $payload)) {
            $data['snapshot'] = $payload['snapshot'];
        }

        return Reference::fromArray($data);
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
