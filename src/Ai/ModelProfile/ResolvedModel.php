<?php

declare(strict_types=1);

namespace App\Ai\ModelProfile;

use Symfony\AI\Platform\PlatformInterface;

/**
 * The materialized result of resolving an operation-facing model profile name
 * (e.g. "proxenos.task.chat") through {@see ModelProfileResolver}. Carries
 * the concrete `symfony/ai` Platform to invoke, the provider model id, and
 * the baseline options to pass at invoke time (max_tokens, temperature, …).
 *
 * Operator-level: callers do not pick provider/model strings themselves
 * (ADR-008). They request a profile by name; the host's config decides the
 * mapping.
 */
final readonly class ResolvedModel
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public PlatformInterface $platform,
        public string $modelId,
        public array $options = [],
    ) {
    }
}
