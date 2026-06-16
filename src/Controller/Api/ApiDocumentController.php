<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\CoreDocument;
use App\Entity\Membership;
use App\Entity\User;
use App\Repository\CoreDocumentRepository;
use App\Repository\MembershipRepository;
use App\TypedEntity\Core\Document\CoreDocumentDeclaration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The `core.document` write/read surface — the slice's "real write" that
 * closes the vertical spine end-to-end (step-02 decision 10). Thin: it
 * validates the inbound payload against the `core.document` schema, mints a
 * UUIDv7, and hands the rest to the {@see CoreDocument} entity + repository.
 *
 *   POST /api/documents
 *     body: {"title": "...", "body": "...", "tags"?: [...], "collection"?: "..."}
 *     header: X-CSRF-Token
 *   -> 201 {"id", "envelope", "data"}
 *
 *   GET /api/documents/{id}
 *   -> 200 {"id", "envelope", "data"} | 404 {"error": "not_found"}
 *
 * Auth mirrors {@see ApiChatController}: the same form_login session and the
 * shared 'chat' CSRF intention so existing SPA tooling validates identically.
 * `id` is returned alongside `{envelope, data}` (the workplan's GET shape) so
 * the SPA can mint a `Reference` from the create/read response without a
 * second round-trip.
 */
#[Route('/api/documents', name: 'api_document_')]
#[IsGranted('ROLE_USER')]
final class ApiDocumentController extends AbstractController
{
    /** Top-level keys the `core.document` schema accepts on write. */
    private const array ALLOWED_KEYS = ['title', 'body', 'tags', 'collection'];

    public function __construct(
        private readonly MembershipRepository $memberships,
        private readonly CoreDocumentRepository $documents,
        private readonly EntityManagerInterface $em,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->validateCsrf($request);
        $membership = $this->resolveMembershipOrAbort();

        $payload = $this->decodeJson($request);

        $unknown = array_diff(array_keys($payload), self::ALLOWED_KEYS);
        if ([] !== $unknown) {
            return $this->validationError(
                \sprintf('Unknown propert%s: %s.', 1 === \count($unknown) ? 'y' : 'ies', implode(', ', $unknown)),
            );
        }

        if (!\is_string($payload['title'] ?? null) || '' === trim($payload['title'])) {
            return $this->validationError('title is required and must be a non-empty string.');
        }
        if (!\is_string($payload['body'] ?? null) || '' === trim($payload['body'])) {
            return $this->validationError('body is required and must be a non-empty string.');
        }

        $tags = [];
        if (\array_key_exists('tags', $payload)) {
            if (!\is_array($payload['tags']) || !array_is_list($payload['tags'])) {
                return $this->validationError('tags must be an array of strings.');
            }
            foreach ($payload['tags'] as $tag) {
                if (!\is_string($tag)) {
                    return $this->validationError('tags must be an array of strings.');
                }
            }
            /** @var list<string> $tags */
            $tags = $payload['tags'];
        }

        $collection = null;
        if (\array_key_exists('collection', $payload) && null !== $payload['collection']) {
            if (!\is_string($payload['collection'])) {
                return $this->validationError('collection must be a string or null.');
            }
            $collection = $payload['collection'];
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $document = new CoreDocument(
                id: Uuid::v7(),
                tenantId: $membership->getTenant()->getId(),
                createdByUserId: $user->getId(),
                title: $payload['title'],
                body: $payload['body'],
                tags: $tags,
                collection: $collection,
                createdAt: $this->clock->now(),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->validationError($e->getMessage());
        }

        $this->em->persist($document);
        $this->em->flush();

        return new JsonResponse($this->present($document), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(string $id): JsonResponse
    {
        $membership = $this->resolveMembershipOrAbort();

        $document = $this->documents->findOneByIdForTenant(
            Uuid::fromString($id),
            $membership->getTenant()->getId(),
        );
        if (null === $document) {
            // Cross-tenant ids are dangling — the repo scopes by tenant, so
            // "exists for someone else" is indistinguishable from "not found".
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->present($document), Response::HTTP_OK);
    }

    /**
     * @return array{id: string, envelope: array<string, mixed>, data: array<string, mixed>}
     */
    private function present(CoreDocument $document): array
    {
        return [
            'id' => $document->getId()->toRfc4122(),
            'envelope' => new CoreDocumentDeclaration()->envelope(),
            'data' => $document->toData(),
        ];
    }

    private function validationError(string $detail): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'validation_failed', 'detail' => $detail],
            Response::HTTP_BAD_REQUEST,
        );
    }

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
     * CSRF protection shared with the SPA chat write surface
     * ({@see ApiChatController}): the 'chat' intention so one token covers
     * every mutating SPA call during the Twig→SPA migration.
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
}
