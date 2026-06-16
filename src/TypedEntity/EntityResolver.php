<?php

declare(strict_types=1);

namespace App\TypedEntity;

use App\Repository\CoreDocumentRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Routes a {@see Reference} to the host-side resolver for its
 * `provider+type` pair (ADR-013a). v0 supports exactly one pair
 * (`core` + `core.document`); any other pair returns a dangling
 * `ResolvedReference` and a debug log entry (not yet wired here — the
 * caller is the diagnostic site). The point of the v0 stub is to lock the
 * dispatcher's shape so part 2 (external providers over ADR-010) is purely
 * additive.
 *
 * On any `core.document` ref:
 *  - the `id` is decoded as a UUIDv7 (the only id shape v0 mints);
 *  - the row is loaded via `CoreDocumentRepository::findOneByIdForTenant`,
 *    so cross-tenant ids are dangling, NOT 404s — the host never leaks
 *    "this id exists in another tenant";
 *  - the resolved instance is the ADR-013 `data` payload
 *    (`CoreDocument::toData()`).
 *
 * Reference `id` opacity is preserved: we attempt a UUID parse but a
 * malformed id (or any provider-specific shape we don't recognize)
 * silently returns dangling — we do not throw. The host is allowed to
 * receive arbitrary `id` strings; degrading to dangling is the correct
 * behavior, not an error.
 */
final readonly class EntityResolver
{
    public function __construct(
        private CoreDocumentRepository $coreDocuments,
    ) {
    }

    public function resolve(Reference $reference, Uuid $tenantId): ResolvedReference
    {
        if ('core' === $reference->provider && 'core.document' === $reference->type) {
            return $this->resolveCoreDocument($reference, $tenantId);
        }

        return new ResolvedReference($reference, null);
    }

    private function resolveCoreDocument(Reference $reference, Uuid $tenantId): ResolvedReference
    {
        try {
            $id = Uuid::fromString($reference->id);
        } catch (\InvalidArgumentException) {
            return new ResolvedReference($reference, null);
        }

        $doc = $this->coreDocuments->findOneByIdForTenant($id, $tenantId);
        if (null === $doc) {
            return new ResolvedReference($reference, null);
        }

        return new ResolvedReference($reference, $doc->toData());
    }
}
