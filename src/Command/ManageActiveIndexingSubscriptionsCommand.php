<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ActiveIndexingService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'active-indexing:manage-subscriptions',
    description: 'Process subscription expirations and grace periods'
)]
class ManageActiveIndexingSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly ActiveIndexingService $activeIndexingService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Active Indexing Subscription Management');

        if ($input->getOption('dry-run')) {
            $io->note('DRY RUN MODE');
            return Command::SUCCESS;
        }

        $expiredToGrace = $this->activeIndexingService->processExpiredToGrace();
        $io->info(sprintf('Moved %d subscription(s) to grace period.', $expiredToGrace));

        $graceEnded = $this->activeIndexingService->processGraceEnded();
        $io->info(sprintf('Expired %d subscription(s) - role removed.', $graceEnded));

        $io->success('Subscription management complete.');
        return Command::SUCCESS;
    }
}
