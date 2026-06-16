<?php

declare(strict_types=1);

namespace App\Ai\ModelProfile;

/**
 * Host-owned seam for ADR-027's model-profile vocabulary
 * (`proxenos.task.chat`, `proxenos.task.reason`, `proxenos.task.embed.text`,
 * …) — the operation-facing names ADR-014 resolves at execution time. The
 * Phase 0.3 turn loop and all later operations (extraction, summarization,
 * embeddings) resolve the model to call the same way: by profile name.
 *
 * Why an interface, not a service map directly:
 *   - swapping providers behind a profile must stay code-free (ADR-023 DoD);
 *   - later policy resolvers (per-tenant overrides, A/B routing, fallback
 *     chains) plug in here without rewriting every operation;
 *   - tests rebind one profile to InMemoryPlatform without touching anything
 *     else (zero credentials required for {@see ChatRespondLoop} coverage).
 */
interface ModelProfileResolver
{
    /**
     * @throws UnknownModelProfile when no mapping exists for $profile
     */
    public function resolve(string $profile): ResolvedModel;
}
