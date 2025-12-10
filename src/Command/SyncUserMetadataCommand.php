<?php

namespace App\Command;

use App\Service\UserMetadataSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-user-metadata',
    description: 'Sync user metadata from Redis cache to database fields',
)]
class SyncUserMetadataCommand extends Command
{
    public function __construct(
        private readonly UserMetadataSyncService $syncService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Number of users to process in each batch', 50)
            ->setHelp('This command synchronizes user metadata from Redis cache to database fields for all users.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = (int) $input->getOption('batch-size');

        $io->title('User Metadata Sync');
        $io->text('Starting synchronization of user metadata from Redis to database...');

        $stats = $this->syncService->syncAllUsers($batchSize);

        $io->success('Metadata sync completed!');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Total users', $stats['total']],
                ['Successfully synced', $stats['synced']],
                ['No metadata found', $stats['no_metadata']],
                ['Errors', $stats['errors']]
            ]
        );

        return Command::SUCCESS;
    }
}

