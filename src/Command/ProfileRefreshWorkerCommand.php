<?php

namespace App\Command;

use App\Message\UpdateProfileProjectionMessage;
use App\Repository\UserEntityRepository;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Background worker that periodically refreshes profile metadata for users.
 *
 * This command runs continuously and triggers profile updates in batches,
 * coalescing requests to avoid overwhelming the relay network.
 */
#[AsCommand(
    name: 'app:profile-refresh-worker',
    description: 'Background worker for periodic profile metadata refresh'
)]
class ProfileRefreshWorkerCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 50;
    private const DEFAULT_INTERVAL_SECONDS = 300; // 5 minutes
    private const DEFAULT_COALESCE_WINDOW_SECONDS = 60; // 1 minute

    public function __construct(
        private readonly UserEntityRepository $userRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Number of users to process in each batch',
                self::DEFAULT_BATCH_SIZE
            )
            ->addOption(
                'interval',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Seconds between full refresh cycles',
                self::DEFAULT_INTERVAL_SECONDS
            )
            ->addOption(
                'coalesce-window',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Seconds to wait before dispatching batch (for coalescing)',
                self::DEFAULT_COALESCE_WINDOW_SECONDS
            )
            ->addOption(
                'run-once',
                null,
                InputOption::VALUE_NONE,
                'Run once and exit (for testing)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int) $input->getOption('batch-size');
        $interval = (int) $input->getOption('interval');
        $coalesceWindow = (int) $input->getOption('coalesce-window');
        $runOnce = $input->getOption('run-once');

        $this->logger->info('Starting profile refresh worker', [
            'batch_size' => $batchSize,
            'interval' => $interval,
            'coalesce_window' => $coalesceWindow,
            'run_once' => $runOnce
        ]);

        $output->writeln('<info>Profile refresh worker started</info>');
        $output->writeln(sprintf('Batch size: %d, Interval: %ds, Coalesce window: %ds',
            $batchSize, $interval, $coalesceWindow));

        do {
            try {
                $this->refreshProfiles($batchSize, $coalesceWindow, $output);

                if (!$runOnce) {
                    $this->logger->info('Waiting for next refresh cycle', ['interval' => $interval]);
                    $output->writeln(sprintf('<comment>Waiting %d seconds until next cycle...</comment>', $interval));
                    sleep($interval);
                }
            } catch (\Exception $e) {
                $this->logger->error('Error in profile refresh worker', [
                    'error' => $e->getMessage()
                ]);
                $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));

                if (!$runOnce) {
                    // Wait a bit before retrying
                    sleep(30);
                }
            }
        } while (!$runOnce);

        $output->writeln('<info>Profile refresh worker stopped</info>');
        return Command::SUCCESS;
    }

    private function refreshProfiles(int $batchSize, int $coalesceWindow, OutputInterface $output): void
    {
        $this->logger->info('Starting profile refresh cycle');
        $output->writeln('<info>Starting profile refresh cycle...</info>');

        // Get all users
        $users = $this->userRepository->findAll();
        $totalUsers = count($users);

        $this->logger->info('Found users to refresh', ['count' => $totalUsers]);
        $output->writeln(sprintf('Found %d users to refresh', $totalUsers));

        if ($totalUsers === 0) {
            $output->writeln('<comment>No users to refresh</comment>');
            return;
        }

        // Group users into batches
        $batches = array_chunk($users, $batchSize);
        $pubkeysBatch = [];
        $dispatchedCount = 0;

        foreach ($batches as $batchIndex => $userBatch) {
            $output->writeln(sprintf('Processing batch %d/%d (%d users)',
                $batchIndex + 1, count($batches), count($userBatch)));

            // Collect pubkeys for this batch
            foreach ($userBatch as $user) {
                try {
                    $npub = $user->getNpub();

                    if (!NostrKeyUtil::isNpub($npub)) {
                        $this->logger->warning('Invalid npub format', ['npub' => $npub]);
                        continue;
                    }

                    $pubkeyHex = NostrKeyUtil::npubToHex($npub);
                    $pubkeysBatch[] = $pubkeyHex;
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to convert npub to hex', [
                        'npub' => $user->getNpub(),
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Dispatch updates for accumulated pubkeys
            if (!empty($pubkeysBatch)) {
                foreach ($pubkeysBatch as $pubkeyHex) {
                    try {
                        $this->messageBus->dispatch(new UpdateProfileProjectionMessage($pubkeyHex));
                        $dispatchedCount++;
                    } catch (\Exception $e) {
                        $this->logger->error('Failed to dispatch profile update', [
                            'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                $this->logger->info('Dispatched batch profile updates', [
                    'count' => count($pubkeysBatch)
                ]);

                $pubkeysBatch = []; // Reset for next batch
            }

            // Coalesce window: wait before next batch
            if ($batchIndex < count($batches) - 1 && $coalesceWindow > 0) {
                $output->writeln(sprintf('<comment>Waiting %ds (coalesce window)...</comment>', $coalesceWindow));
                sleep($coalesceWindow);
            }
        }

        $this->logger->info('Profile refresh cycle complete', [
            'total_users' => $totalUsers,
            'dispatched' => $dispatchedCount
        ]);

        $output->writeln(sprintf('<info>Refresh cycle complete: %d/%d users dispatched</info>',
            $dispatchedCount, $totalUsers));
    }
}
