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
            ->addArgument('arg1', InputArgument::REQUIRED, 'User pubkey')
            ->addArgument('arg2', InputArgument::REQUIRED, 'Role to set');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $npub = $input->getArgument('arg1');
        $role = $input->getArgument('arg2');
        if (!str_starts_with($role, 'ROLE_')) {
            return Command::INVALID;
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['npub' => $npub]);
        if (!$user) {
            return Command::FAILURE;
        }

        $user->addRole($role);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $output->writeln(sprintf('User %s elevated to role %s', $npub, $role));

        return Command::SUCCESS;
    }
}
