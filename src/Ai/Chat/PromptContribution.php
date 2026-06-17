<?php

declare(strict_types=1);

namespace App\Ai\Chat;

/**
 * The cross-lane prompt-assembly unit (step-02 chunk 7, decision 7).
 *
 * Both the entity-context lane (this slice) and Lane D's system-prompt lane
 * converge on this one minimal shape so neither clobbers the other when they
 * both contribute to a turn. The loop folds an ordered list of these into the
 * `MessageBag`; the agreed ordering contract is
 * `[ systemPrompt, entityContext, conversationHistory ]` — expressed here as
 * the integer `weight` (lower sorts earlier). This lane emits
 * entity-context contributions only; Lane D emits system-prompt
 * contributions. Either lane can land first and the assembler tolerates zero
 * contributions of the other kind.
 *
 * Deliberately minimal — `{ weight, role, text }` and nothing else. Anything
 * richer (cache breakpoints, segment ids, ordering machinery) is ADR-018's
 * job, explicitly out of scope for v0.
 */
final readonly class PromptContribution
{
    public const string ROLE_SYSTEM = 'system';
    public const string ROLE_USER = 'user';
    public const string ROLE_ASSISTANT = 'assistant';

    /**
     * @param 'system'|'user'|'assistant' $role
     */
    public function __construct(
        public int $weight,
        public string $role,
        public string $text,
    ) {
        if (!\in_array($role, [self::ROLE_SYSTEM, self::ROLE_USER, self::ROLE_ASSISTANT], true)) {
            throw new \InvalidArgumentException(\sprintf('PromptContribution.role must be system|user|assistant, got %s.', $role));
        }
    }
}
