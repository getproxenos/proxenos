<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Placeholder long-running reactor loop. There is nothing to long-poll in Phase 0,
 * but the compose `reactor` service is wired now (per bootstrap plan) as a real,
 * ready slot for a future event-loop worker. No Revolt/Amp dependency yet — when a
 * real event source exists, this becomes a fiber loop.
 */
#[AsCommand(name: 'app:reactor:run', description: 'Placeholder reactor loop (no event source yet).')]
final class ReactorRunCommand extends Command
{
    public function __construct(private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>reactor: started (placeholder — nothing to poll yet)</info>');

        // @phpstan-ignore while.alwaysTrue (intentional: long-running placeholder loop)
        while (true) {
            sleep(60);
            $this->logger->debug('reactor heartbeat');
        }
    }
}
