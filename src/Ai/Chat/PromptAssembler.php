<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use App\Conversation\ThreadAttachmentService;
use App\TypedEntity\Core\Document\CoreDocumentDeclaration;
use App\TypedEntity\EntityResolver;
use App\TypedEntity\Reference;
use App\TypedEntity\Renderer\EntityRenderer;
use App\TypedEntity\Renderer\RenderedEntity;
use App\TypedEntity\ResolvedReference;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Entity-aware prompt assembly (step-02 chunk 7, decision 7). Replaces the
 * loop's dumb projection-only concat for the *context* portion of the prompt.
 *
 * Pipeline (one pass over a thread's pinned references):
 *
 *   listForThread → EntityResolver::resolve → expansion-policy slot
 *     (`pill` honored; `summary`/`full` downgraded to `pill` with a debug log,
 *      transclusion guardrail) → EntityRenderer::render(pill)
 *     → ContextBudgetPlanner::admitAttachedEntities → serialize the admitted
 *       entities into ONE entity-context fragment
 *
 * The fragment is returned as a single system-role {@see PromptContribution}.
 * Dangling references (resolver returned `instance === null`) are skipped; a
 * thread with no attachments yields `[]` (the loop tolerates zero).
 *
 * The infra-touching entrypoint {@see self::assemble()} pulls + resolves, then
 * delegates the entire pure decision to {@see self::assembleResolved()} — which
 * takes already-resolved references and is the unit-tested core (and a reuse
 * seam for a future transclusion expander that resolves elsewhere).
 */
final class PromptAssembler
{
    /**
     * Ordering weight for entity-context contributions. The agreed cross-lane
     * contract is `[ systemPrompt, entityContext, conversationHistory ]`
     * (decision 7); Lane D's system prompt sorts ahead with a lower weight,
     * conversation history is appended after all contributions by the loop.
     */
    public const int ENTITY_CONTEXT_WEIGHT = 100;

    public function __construct(
        private readonly ThreadAttachmentService $attachments,
        private readonly EntityResolver $resolver,
        private readonly EntityRenderer $renderer,
        private readonly ContextBudgetPlanner $budget,
        private readonly CoreDocumentDeclaration $coreDocument,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * CONTRACT-OUT: the ordered entity-context contributions for a thread.
     *
     * @return list<PromptContribution>
     */
    public function assemble(Uuid $threadId, Uuid $tenantId): array
    {
        $resolved = array_map(
            fn (Reference $reference): ResolvedReference => $this->resolver->resolve($reference, $tenantId),
            $this->attachments->listForThread($threadId),
        );

        return $this->assembleResolved($resolved);
    }

    /**
     * Pure core: turn already-resolved references into the entity-context
     * contributions. No I/O — unit-tested directly.
     *
     * @param list<ResolvedReference> $resolved in attach order
     *
     * @return list<PromptContribution>
     */
    public function assembleResolved(array $resolved): array
    {
        $candidates = [];
        foreach ($resolved as $resolvedReference) {
            // Skip dangling references — the host never serializes a ref it
            // could not resolve to an instance.
            if (!$resolvedReference->isResolved()) {
                continue;
            }

            $reference = $resolvedReference->reference;
            $envelope = $this->envelopeFor($reference);
            if (null === $envelope) {
                // No declaration for this provider/type pair (v0 only knows
                // core.document); cannot schema-render, so skip.
                continue;
            }

            // Route through the expansion-policy slot. v0 honors `pill` only;
            // `summary`/`full` are downgraded to `pill` with a debug log. We
            // read the slot and never hardcode "pill" (transclusion guardrail).
            $mode = $this->resolveExpansionMode($reference);

            /** @var array<string, mixed> $instance */
            $instance = $resolvedReference->instance ?? [];
            $rendered = $this->renderer->render($envelope, $instance, $mode);

            $candidates[] = $this->candidateFor($reference, $rendered);
        }

        $admitted = $this->budget->admitAttachedEntities($candidates);
        if ([] === $admitted) {
            return [];
        }

        return [new PromptContribution(
            weight: self::ENTITY_CONTEXT_WEIGHT,
            role: PromptContribution::ROLE_SYSTEM,
            text: $this->serializeFragment($admitted),
        )];
    }

    /**
     * The expansion slot governs render shape. v0: `pill` honored as-is;
     * `summary`/`full` downgraded to `pill` with a debug log. Returns the
     * renderer mode to use.
     *
     * @return 'pill'
     */
    private function resolveExpansionMode(Reference $reference): string
    {
        if (Reference::EXPANSION_PILL !== $reference->expansion) {
            $this->logger->debug('Entity expansion downgraded to pill; v0 honors the pill slot only (transclusion deferred).', [
                'provider' => $reference->provider,
                'type' => $reference->type,
                'id' => $reference->id,
                'requested_expansion' => $reference->expansion,
            ]);
        }

        return RenderedEntity::KIND_PILL;
    }

    /**
     * Build the budget candidate from the pill render. v0 honors `pill` only,
     * so the `full` and `summary` ladder rungs both carry the pill line; the
     * `reference` floor carries the bare identity line.
     */
    private function candidateFor(Reference $reference, RenderedEntity $rendered): AttachmentCandidate
    {
        $pill = $this->pillLine($rendered);

        return new AttachmentCandidate(
            full: $pill,
            summary: $pill,
            reference: $this->referenceLine($reference, $rendered),
        );
    }

    private function pillLine(RenderedEntity $rendered): string
    {
        return '- '.$rendered->title;
    }

    private function referenceLine(Reference $reference, RenderedEntity $rendered): string
    {
        return \sprintf('- %s (reference: %s/%s/%s)', $rendered->title, $reference->provider, $reference->type, $reference->id);
    }

    /**
     * @param list<AdmittedEntity> $admitted
     */
    private function serializeFragment(array $admitted): string
    {
        $lines = array_map(static fn (AdmittedEntity $entity): string => $entity->text, $admitted);

        return "Attached context — typed entities the user pinned to this thread:\n".implode("\n", $lines);
    }

    /**
     * Resolve the ADR-013 envelope for a reference's provider/type pair. v0
     * knows exactly one pair (`core` + `core.document`); anything else returns
     * null and is skipped by the caller.
     *
     * @return array<string, mixed>|null
     */
    private function envelopeFor(Reference $reference): ?array
    {
        if ($reference->provider === $this->coreDocument->provider() && $reference->type === $this->coreDocument->type()) {
            return $this->coreDocument->envelope();
        }

        return null;
    }
}
