<?php

declare(strict_types=1);

namespace App\Ai\ModelProfile;

use Psr\Container\ContainerInterface;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Config-driven {@see ModelProfileResolver} for v0. Reads the map injected as
 * `proxenos.model_profiles` (see config/packages/proxenos.yaml) and dereferences
 * each profile's `platform:` key against a service locator of named Platforms
 * (wired in config/services.yaml).
 *
 * Per ADR-023, the "swap by config" DoD is satisfied here: changing a
 * profile's `platform:` value from `anthropic` to `generic.default` (or any
 * future bridge) routes the loop through a different provider on the next
 * request — no code change, no rebuild.
 */
final class ConfigModelProfileResolver implements ModelProfileResolver
{
    /**
     * @param array<string, array{platform: string, model: string, options?: array<string, mixed>}> $profiles
     * @param ContainerInterface                                                                    $platforms service locator keyed by platform alias
     */
    public function __construct(
        private readonly array $profiles,
        private readonly ContainerInterface $platforms,
    ) {
    }

    public function resolve(string $profile): ResolvedModel
    {
        if (!isset($this->profiles[$profile])) {
            throw UnknownModelProfile::named($profile);
        }

        $spec = $this->profiles[$profile];

        if (!isset($spec['platform'], $spec['model'])) {
            throw new \LogicException(\sprintf('Model profile "%s" is missing required keys (platform, model).', $profile));
        }

        $platformKey = (string) $spec['platform'];
        $modelId = (string) $spec['model'];

        if ('' === $modelId) {
            throw new \LogicException(\sprintf('Model profile "%s" has an empty model id. Set the LLM_CHAT_MODEL env var or pin a model.', $profile));
        }

        if (!$this->platforms->has($platformKey)) {
            throw new \LogicException(\sprintf('Model profile "%s" references unknown platform "%s". Register it in config/services.yaml.', $profile, $platformKey));
        }

        $platform = $this->platforms->get($platformKey);
        if (!$platform instanceof PlatformInterface) {
            throw new \LogicException(\sprintf('Service "%s" is not a Symfony\\AI\\Platform\\PlatformInterface.', $platformKey));
        }

        /** @var array<string, mixed> $options */
        $options = $spec['options'] ?? [];

        return new ResolvedModel($platform, $modelId, $options);
    }
}
