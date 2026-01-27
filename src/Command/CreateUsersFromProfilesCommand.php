<?php

namespace App\Command;

use App\Message\UpdateProfileProjectionMessage;
use App\Repository\EventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:create-users-from-profiles',
    description: 'Create User entities from backfilled profile metadata events'
)]
class CreateUsersFromProfilesCommand extends Command
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of profiles to process',
                null
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Number of messages to dispatch before pausing',
                50
            )
            ->setHelp(
                'This command dispatches UpdateProfileProjectionMessage for each unique pubkey ' .
                'found in kind 0 (metadata) events. This creates User entities if they don\'t exist ' .
                'and updates their metadata from Redis cache.' . "\n\n" .
                'Prerequisites:' . "\n" .
                '  1. Run profiles:backfill --cache to populate event table and Redis' . "\n" .
                '  2. Ensure worker is running to process messages' . "\n\n" .
                'Examples:' . "\n" .
                '  # Process all backfilled profiles:' . "\n" .
                '  php bin/console app:create-users-from-profiles' . "\n\n" .
                '  # Process only 100 profiles:' . "\n" .
                '  php bin/console app:create-users-from-profiles --limit=100' . "\n\n" .
                '  # Small batches with delays:' . "\n" .
                '  php bin/console app:create-users-from-profiles --batch-size=10'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $batchSize = (int) $input->getOption('batch-size');

        $io->title('Create Users from Backfilled Profiles');

        // Get distinct pubkeys from kind 0 events
        $io->section('Fetching unique pubkeys from metadata events...');

        $qb = $this->eventRepository->createQueryBuilder('e');
        $qb->select('e.pubkey, MAX(e.created_at) as max_created')
            ->where('e.kind = 0')
            ->groupBy('e.pubkey')
            ->orderBy('max_created', 'DESC');

        if ($limit) {
            $qb->setMaxResults($limit);
        }

        $results = $qb->getQuery()->getResult();

        if (empty($results)) {
            $io->warning('No metadata events (kind 0) found in database.');
            $io->note('Run: php bin/console profiles:backfill --cache');
            return Command::SUCCESS;
        }

        $pubkeys = array_map(fn($r) => $r['pubkey'], $results);
        $totalPubkeys = count($pubkeys);

        $io->writeln(sprintf('Found %d unique pubkeys to process', $totalPubkeys));
        $io->newLine();

        // Dispatch messages in batches
        $io->section('Dispatching profile projection messages...');
        $progressBar = $io->createProgressBar($totalPubkeys);
        $progressBar->setFormat('very_verbose');

        $dispatchedCount = 0;
        $batchCount = 0;

        foreach ($pubkeys as $pubkey) {
            try {
                // Dispatch async message to create/update user
                $this->messageBus->dispatch(new UpdateProfileProjectionMessage($pubkey));
                $dispatchedCount++;
                $batchCount++;

                // Pause between batches to avoid overwhelming the system
                if ($batchCount >= $batchSize) {
                    $this->logger->info('Dispatched batch of profile updates', [
                        'batch_size' => $batchCount,
                        'total_dispatched' => $dispatchedCount
                    ]);
                    $batchCount = 0;
                    usleep(500000); // 500ms pause between batches
                }

                $progressBar->advance();

            } catch (\Throwable $e) {
                $this->logger->error('Failed to dispatch profile projection', [
                    'pubkey' => substr($pubkey, 0, 16) . '...',
                    'error' => $e->getMessage()
                ]);
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        // Summary
        $io->success('Profile projection messages dispatched!');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Total pubkeys found', $totalPubkeys],
                ['Messages dispatched', $dispatchedCount],
            ]
        );

        $io->note([
            'Messages have been queued for async processing.',
            'Make sure the worker is running: docker compose logs -f worker',
            'Users will be created/updated as messages are processed.',
            '',
            'To verify results:',
            '  docker compose exec database psql -U dn_user -d newsroom_db -c "SELECT COUNT(*) FROM app_user;"',
            '  docker compose logs -f worker | grep "Profile projection"'
        ]);

        return Command::SUCCESS;
    }
}
