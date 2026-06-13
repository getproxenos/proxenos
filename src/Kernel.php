<?php

declare(strict_types=1);

namespace App;

use App\DependencyInjection\Compiler\RemoveOrphanedAiAgentServicesPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        // Drop agent-coupled service definitions that ai-bundle (^0.8) leaves
        // dangling when symfony/ai-agent is intentionally not installed
        // (ADR-019: Platform only — no Agent component). Revisit at symfony/ai 1.0.
        $container->addCompilerPass(new RemoveOrphanedAiAgentServicesPass());
    }
}
