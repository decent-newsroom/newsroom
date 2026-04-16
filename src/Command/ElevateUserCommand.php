<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'user:elevate',
    description: 'Assign a role to user'
)]
class ElevateUserCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('npub', InputArgument::REQUIRED, 'User npub (public key)')
            ->addArgument('role', InputArgument::REQUIRED, 'Role to assign (e.g. ROLE_ADMIN)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $npub = $input->getArgument('npub');
        $role = $input->getArgument('role');
        if (!str_starts_with($role, 'ROLE_')) {
            $output->writeln(sprintf('<error>Invalid role "%s": must start with ROLE_</error>', $role));
            return Command::INVALID;
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['npub' => $npub]);
        if (!$user) {
            $output->writeln(sprintf('<error>User not found with npub: %s</error>', $npub));
            return Command::FAILURE;
        }

        $user->addRole($role);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $output->writeln(sprintf('<info>User %s elevated to role %s</info>', $npub, $role));

        return Command::SUCCESS;
    }
}
