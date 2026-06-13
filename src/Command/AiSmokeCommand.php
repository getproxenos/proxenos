<?php

declare(strict_types=1);

namespace App\Command;

use App\Client\Llm\LlmClient;
use App\Client\Llm\LlmException;
use App\Config\LlmConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Phase 0.0 definition-of-done smoke test: "a configured symfony/ai provider
 * bridge returns a completion." Guarded so it no-ops (success) when the Platform
 * is unconfigured, which keeps CI green without a key. Point LLM_BASE_URL at a
 * local Ollama (/v1) for a key-free run.
 */
#[AsCommand(name: 'app:ai:smoke', description: 'Send a one-shot completion to the configured symfony/ai Platform.')]
final class AiSmokeCommand extends Command
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly LlmConfig $config,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->config->isConfigured()) {
            $io->warning('LLM not configured (set LLM_BASE_URL + LLM_CHAT_MODEL — e.g. a local Ollama at http://host:11434/v1). Skipping.');

            return Command::SUCCESS;
        }

        try {
            $reply = $this->llm->complete(
                'You are a smoke test. Reply with exactly: OK',
                'Say OK.',
            );
            $io->success('Platform replied: '.trim($reply));

            return Command::SUCCESS;
        } catch (LlmException $e) {
            $io->error('LLM call failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
