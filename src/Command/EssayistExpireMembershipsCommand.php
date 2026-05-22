<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Essayist\EssayistMembershipService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'essayist:expire-memberships',
    description: 'Revoke ROLE_ESSAYIST_MEMBER from users whose latest membership row has expired'
)]
final class EssayistExpireMembershipsCommand extends Command
{
    public function __construct(
        private readonly EssayistMembershipService $membershipService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $revoked = $this->membershipService->expireLapsed();
        $io->success(sprintf('Revoked membership from %d user(s).', $revoked));
        return Command::SUCCESS;
    }
}

