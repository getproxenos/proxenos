<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use App\Repository\ThreadRepository;
use App\Repository\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the effective system prompt for a turn into a weight-0
 * {@see PromptContribution} (step-03 chunk D9, decision 5).
 *
 * Precedence: a per-thread override wins over the user's global default; a
 * blank/empty effective prompt yields NO contribution (`null`). The non-null
 * contribution sorts at {@see self::SYSTEM_PROMPT_WEIGHT} — strictly below
 * {@see PromptAssembler::ENTITY_CONTEXT_WEIGHT} — so the loop's existing
 * weight-ascending sort places it ahead of entity context and conversation
 * history (decision 7's ordered contract
 * `[ systemPrompt(0), entityContext(100), conversationHistory ]`).
 *
 * The infra-touching {@see self::forThread()} reads the projection rows then
 * delegates the precedence decision to the pure {@see self::resolveEffective()},
 * which is the unit-tested core (mirrors PromptAssembler's assemble/resolve
 * split).
 */
final class SystemPromptResolver
{
    /**
     * Ordering weight for the system-prompt contribution. Strictly below
     * {@see PromptAssembler::ENTITY_CONTEXT_WEIGHT} (100) so the system prompt
     * sorts to the FRONT of the prompt, ahead of entity context (decision 7).
     */
    public const int SYSTEM_PROMPT_WEIGHT = 0;

    public function __construct(
        private readonly ThreadRepository $threads,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * The effective system-prompt contribution for a turn, or `null` when there
     * is none. Reads the per-thread override (tenant-scoped) and the user's
     * global default, then applies the precedence rule.
     */
    public function forThread(Uuid $threadId, Uuid $tenantId, Uuid $userId): ?PromptContribution
    {
        $thread = $this->threads->find($threadId);
        // Tenant guard: only honor an override that belongs to the calling
        // tenant. A thread row absent (lazy-created later) or cross-tenant
        // contributes no override — the default still applies.
        $override = (null !== $thread && $thread->getTenantId()->equals($tenantId))
            ? $thread->getSystemPrompt()
            : null;

        $default = $this->users->find($userId)?->getSystemPromptDefault();

        return self::resolveEffective($override, $default);
    }

    /**
     * Pure precedence core: override > default > none. A blank/empty override
     * falls through to the default; a blank/empty effective value yields no
     * contribution. Static + side-effect-free so it is unit-tested directly,
     * without standing up the repository-backed {@see self::forThread()}.
     */
    public static function resolveEffective(?string $override, ?string $default): ?PromptContribution
    {
        $effective = (null !== $override && '' !== trim($override)) ? $override : $default;

        if (null === $effective || '' === trim($effective)) {
            return null;
        }

        return new PromptContribution(
            weight: self::SYSTEM_PROMPT_WEIGHT,
            role: PromptContribution::ROLE_SYSTEM,
            text: $effective,
        );
    }
}
