<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Chat;

use App\Ai\Chat\PromptAssembler;
use App\Ai\Chat\PromptContribution;
use App\Ai\Chat\SystemPromptResolver;
use PHPUnit\Framework\TestCase;

/**
 * Locks the system-prompt precedence core (step-03 chunk D9, decision 5):
 * per-thread override > user global default > none, with blank/empty yielding
 * no contribution. Exercises the pure static {@see SystemPromptResolver::resolveEffective()}
 * directly so the rule is covered without DB I/O (the repository-backed
 * {@see SystemPromptResolver::forThread()} just feeds it the two stored values
 * and is covered end-to-end by the functional injection test).
 */
final class SystemPromptResolverTest extends TestCase
{
    public function testWeightIsZeroAndBelowEntityContext(): void
    {
        self::assertSame(0, SystemPromptResolver::SYSTEM_PROMPT_WEIGHT);
        self::assertLessThan(PromptAssembler::ENTITY_CONTEXT_WEIGHT, SystemPromptResolver::SYSTEM_PROMPT_WEIGHT);
    }

    public function testOverrideWinsOverDefault(): void
    {
        $contribution = SystemPromptResolver::resolveEffective('You are terse.', 'You are verbose.');

        self::assertInstanceOf(PromptContribution::class, $contribution);
        self::assertSame('You are terse.', $contribution->text);
        self::assertSame(PromptContribution::ROLE_SYSTEM, $contribution->role);
        self::assertSame(SystemPromptResolver::SYSTEM_PROMPT_WEIGHT, $contribution->weight);
    }

    public function testFallsBackToDefaultWhenNoOverride(): void
    {
        $contribution = SystemPromptResolver::resolveEffective(null, 'Global persona.');

        self::assertInstanceOf(PromptContribution::class, $contribution);
        self::assertSame('Global persona.', $contribution->text);
    }

    public function testBlankOverrideFallsThroughToDefault(): void
    {
        $contribution = SystemPromptResolver::resolveEffective('   ', 'Global persona.');

        self::assertInstanceOf(PromptContribution::class, $contribution);
        self::assertSame('Global persona.', $contribution->text);
    }

    public function testNoOverrideAndNoDefaultYieldsNull(): void
    {
        self::assertNull(SystemPromptResolver::resolveEffective(null, null));
    }

    public function testBlankEverywhereYieldsNull(): void
    {
        self::assertNull(SystemPromptResolver::resolveEffective('   ', '   '));
        self::assertNull(SystemPromptResolver::resolveEffective('', null));
        self::assertNull(SystemPromptResolver::resolveEffective(null, ''));
    }
}
