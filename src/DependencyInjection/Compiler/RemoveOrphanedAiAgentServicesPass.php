<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Works around a pre-1.0 `symfony/ai-bundle` (`^0.8`) wiring bug for installs
 * that adopt the AI Platform **without** `symfony/ai-agent` (a locked decision —
 * ADR-019: Platform only, no Agent component / tool loop).
 *
 * The bundle gates some agent-coupled services on the wrong package: e.g.
 * `ai.tool.validate_tool_call_arguments_listener` is kept whenever
 * `symfony/validator` is present, and `ai.traceable_toolbox` is kept in debug,
 * yet both classes ship in the un-installed `symfony/ai-agent`. That leaves the
 * container referencing classes that do not exist, so `lint:container` (and boot)
 * fail.
 *
 * Rather than pulling in the agent package just to satisfy the bundle, drop any
 * definition whose class is a `Symfony\AI\Agent\` class that is not loadable.
 * The matching `ai.data_collector` only consumes these via a `tagged_iterator`,
 * which tolerates zero matches, so removal is safe. Revisit at symfony/ai 1.0.
 */
final class RemoveOrphanedAiAgentServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();

            if (null !== $class
                && str_starts_with($class, 'Symfony\\AI\\Agent\\')
                && !class_exists($class)
            ) {
                $container->removeDefinition($id);
            }
        }
    }
}
