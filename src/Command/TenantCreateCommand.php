<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mints a Tenant. v0 collapses account/tenant/workspace into one entity
 * (ADR-021); UI may call it "account" but the schema and tooling say `Tenant`.
 * On success the `core://tenants/{uuid}` URI is written to stdout so a
 * subsequent `app:user:create --tenant=<slug>` is one paste away.
 */
#[AsCommand(name: 'app:tenant:create', description: 'Create a new tenant (a.k.a. account/workspace in v0; ADR-021).')]
final class TenantCreateCommand extends Command
{
    private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenants,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'URL-safe identifier (kebab-case, 1–64 chars).')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Human-readable name.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $slug = (string) ($input->getOption('slug') ?? $io->ask('Slug', null, $this->validateSlug(...)));
        $name = (string) ($input->getOption('name') ?? $io->ask('Name', null, $this->validateName(...)));

        try {
            $slug = $this->validateSlug($slug);
            $name = $this->validateName($name);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (null !== $this->tenants->findOneBySlug($slug)) {
            $io->error(sprintf('Tenant with slug "%s" already exists.', $slug));

            return Command::FAILURE;
        }

        $tenant = new Tenant($slug, $name, $this->clock);

        try {
            $this->em->persist($tenant);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            $io->error(sprintf('Tenant with slug "%s" already exists.', $slug));

            return Command::FAILURE;
        }

        $io->success(sprintf('Created tenant %s ("%s").', $tenant->getSlug(), $tenant->getName()));
        $output->writeln($tenant->coreUri());

        return Command::SUCCESS;
    }

    private function validateSlug(?string $value): string
    {
        $value = trim((string) $value);
        if ('' === $value || 1 !== preg_match(self::SLUG_PATTERN, $value)) {
            throw new \InvalidArgumentException('Slug must be kebab-case, 1–64 chars, [a-z0-9-], not starting/ending with a hyphen.');
        }

        return $value;
    }

    private function validateName(?string $value): string
    {
        $value = trim((string) $value);
        if ('' === $value || mb_strlen($value) > 200) {
            throw new \InvalidArgumentException('Name must be non-empty and at most 200 characters.');
        }

        return $value;
    }
}
