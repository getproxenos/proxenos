<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Mints a User and attaches them as `owner` of a named Tenant in a single
 * transaction (ADR-020/021). Email is lowercased at the write boundary;
 * password is hashed via the configured `auto` hasher. v0 has no
 * registration UI — this is the only path to a usable user.
 */
#[AsCommand(name: 'app:user:create', description: 'Create a user and attach an owner membership to a tenant (ADR-020).')]
final class UserCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly TenantRepository $tenants,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address (lowercased on save).')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant slug to attach as owner.')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password (omit for hidden prompt).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = strtolower(trim((string) ($input->getOption('email') ?? $io->ask('Email'))));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            $io->error('Email is not a valid address (max 254 chars).');

            return Command::FAILURE;
        }

        $tenantSlug = (string) ($input->getOption('tenant') ?? $io->ask('Tenant slug'));
        $tenant = $this->tenants->findOneBySlug($tenantSlug);
        if (null === $tenant) {
            $io->error(sprintf('Tenant "%s" not found. Run app:tenant:create first.', $tenantSlug));

            return Command::FAILURE;
        }

        if (null !== $this->users->findOneByEmail($email)) {
            $io->error(sprintf('User "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $password = (string) ($input->getOption('password') ?? $io->askHidden('Password (input hidden)'));
        if (mb_strlen($password) < 8) {
            $io->error('Password must be at least 8 characters.');

            return Command::FAILURE;
        }

        // Hash off a transient User instance so the hasher knows which entry
        // in `password_hashers` to use (it dispatches by class).
        $hash = $this->hasher->hashPassword(new TransientUserForHashing(), $password);

        $user = new User($email, $hash, $this->clock);
        $membership = new Membership($user, $tenant, MembershipRole::OWNER, $this->clock);

        try {
            $this->em->wrapInTransaction(function () use ($user, $membership): void {
                $this->em->persist($user);
                $this->em->persist($membership);
                $this->em->flush();
            });
        } catch (UniqueConstraintViolationException) {
            $io->error(sprintf('User "%s" already exists (race).', $email));

            return Command::FAILURE;
        }

        $io->success(sprintf('Created user %s (owner of "%s").', $user->getEmail(), $tenant->getSlug()));
        $output->writeln($user->coreUri());
        $output->writeln('owner-of '.$tenant->coreUri());

        return Command::SUCCESS;
    }
}

/**
 * Marker the hasher uses to pick the configured `auto` algorithm. We can't
 * use App\Entity\User here because constructing it requires the (not-yet-known)
 * hash; the hasher only looks at the class identity to route to a hasher.
 */
final class TransientUserForHashing implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
