<?php

declare(strict_types=1);

namespace App\Command;

use App\Conversation\ProjectionFolder;
use App\Repository\ConversationEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Reconstructs a thread's projections (threads / turns / messages /
 * message_parts) from its `conversation_events` log. The fold logic is the
 * same `ProjectionFolder` the write path uses — that symmetry is what makes
 * "projections are rebuildable" (ADR-004) operational rather than aspirational
 * (ADR-022).
 *
 * Scope is one thread per invocation; bulk rebuilds are a future loop on top.
 */
#[AsCommand(name: 'app:projections:rebuild', description: 'Rebuild thread projections from conversation_events.')]
final class ProjectionsRebuildCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationEventRepository $events,
        private readonly ProjectionFolder $folder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('thread', InputArgument::REQUIRED, 'Thread UUID (RFC 4122) to rebuild.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $threadArg = (string) $input->getArgument('thread');
        if (!Uuid::isValid($threadArg)) {
            $io->error(sprintf('Invalid thread UUID: %s', $threadArg));

            return Command::FAILURE;
        }
        $threadId = Uuid::fromString($threadArg);

        $events = $this->events->findByThreadOrdered($threadId);
        if ([] === $events) {
            $io->warning(sprintf('No conversation_events found for thread %s — nothing to rebuild.', $threadId->toRfc4122()));

            return Command::SUCCESS;
        }

        $count = $this->em->wrapInTransaction(function () use ($threadId, $events): int {
            $conn = $this->em->getConnection();
            $conn->executeStatement('DELETE FROM message_parts WHERE thread_id = ?', [$threadId->toRfc4122()]);
            $conn->executeStatement('DELETE FROM messages WHERE thread_id = ?', [$threadId->toRfc4122()]);
            $conn->executeStatement('DELETE FROM turns WHERE thread_id = ?', [$threadId->toRfc4122()]);
            $conn->executeStatement('DELETE FROM threads WHERE id = ?', [$threadId->toRfc4122()]);
            $this->em->clear();

            $applied = 0;
            foreach ($events as $event) {
                // Folder reads from the event only; the detached entity is fine.
                $this->folder->apply($event);
                ++$applied;
            }

            return $applied;
        });

        $io->success(sprintf('Rebuilt projections for thread %s from %d event(s).', $threadId->toRfc4122(), $count));

        return Command::SUCCESS;
    }
}
