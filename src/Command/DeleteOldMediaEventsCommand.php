<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Delete media events (kinds 20, 21, 22, 34235, 34236) older than a specified age.
 *
 * Media events often don't get surfaced in feeds and can accumulate greatly,
 * so this command helps clean up old entries to maintain database health.
 *
 * Usage:
 *   docker compose exec php bin/console admin:delete-old-media-events
 *   docker compose exec php bin/console admin:delete-old-media-events --days=60
 *   docker compose exec php bin/console admin:delete-old-media-events --days=30 --dry-run
 *   docker compose exec php bin/console admin:delete-old-media-events --days=30 --confirm
 *
 * Performance:
 *   - Deletion completes in seconds to minutes depending on volume
 *   - Uses direct SQL DELETE for speed
 */
#[AsCommand(
    name: 'admin:delete-old-media-events',
    description: 'Delete media events (kinds 20,21,22,34235,34236) older than specified days',
)]
class DeleteOldMediaEventsCommand extends Command
{
    // Media event kinds: 20 (image), 21 (video), 22 (short video), 34235 (addressable video), 34236 (addressable short video)
    private const MEDIA_KINDS = [20, 21, 22, 34235, 34236];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_OPTIONAL, 'Delete events older than this many days (default: 60)', '60')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview counts without deleting')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Skip confirmation prompt (dangerous!)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $daysInput = (int) $input->getOption('days');
        $dryRun = $input->getOption('dry-run');
        $confirm = $input->getOption('confirm');

        // Validate input
        if ($daysInput <= 0) {
            $io->error('Days must be a positive integer.');
            return Command::FAILURE;
        }

        // Calculate cutoff timestamp (now - N days)
        $cutoffTimestamp = time() - ($daysInput * 86400);
        $cutoffDate = date('Y-m-d H:i:s', $cutoffTimestamp);

        $conn = $this->em->getConnection();

        // Get counts before deletion
        $counts = $this->getCounts($conn, $cutoffTimestamp);

        if ($counts['total'] === 0) {
            $io->info(sprintf('No media events found older than %d days.', $daysInput));
            return Command::SUCCESS;
        }

        // Show what will be deleted
        $io->section('Deletion Summary');
        $io->listing([
            sprintf('Media events older than: %s (%d days ago)', $cutoffDate, $daysInput),
            sprintf('Total media events to delete: %d', $counts['total']),
            sprintf('  • Kind 20 (images): %d', $counts['kind_20']),
            sprintf('  • Kind 21 (videos): %d', $counts['kind_21']),
            sprintf('  • Kind 22 (short videos): %d', $counts['kind_22']),
            sprintf('  • Kind 34235 (addressable videos): %d', $counts['kind_34235']),
            sprintf('  • Kind 34236 (addressable short videos): %d', $counts['kind_34236']),
        ]);

        if ($dryRun) {
            $io->success('[DRY RUN] No changes made.');
            return Command::SUCCESS;
        }

        // Confirm dangerous action
        if (!$confirm) {
            $io->warning(sprintf(
                'This will PERMANENTLY DELETE %d media event(s) older than %d days',
                $counts['total'],
                $daysInput,
            ));
            if (!$io->confirm('Are you absolutely sure?', false)) {
                $io->info('Aborted.');
                return Command::SUCCESS;
            }
        }

        // Execute deletion
        $io->section('Deleting...');
        $progress = $io->createProgressBar(1);
        $progress->setFormat('%current%/%max% [%bar%] %message%');
        $progress->start();

        try {
            $deletedEvents = $this->deleteOldMediaEvents($conn, $cutoffTimestamp);
            $progress->setMessage('Media events deleted');
            $progress->advance();
            $progress->finish();
            $io->newLine(2);

            // Log the operation
            $this->logger->warning('admin:delete-old-media-events executed', [
                'days_ago' => $daysInput,
                'cutoff_timestamp' => $cutoffTimestamp,
                'deleted_events' => $deletedEvents,
                'deleted_by_kind' => [
                    'kind_20' => $counts['kind_20'],
                    'kind_21' => $counts['kind_21'],
                    'kind_22' => $counts['kind_22'],
                    'kind_34235' => $counts['kind_34235'],
                    'kind_34236' => $counts['kind_34236'],
                ],
            ]);

            $io->success(sprintf(
                'Deleted %d media event(s) older than %d days',
                $deletedEvents,
                $daysInput,
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $progress->finish();
            $io->error(sprintf('Deletion failed: %s', $e->getMessage()));
            $this->logger->error('admin:delete-old-media-events failed', [
                'days_ago' => $daysInput,
                'cutoff_timestamp' => $cutoffTimestamp,
                'error' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Get counts of events by kind that will be deleted.
     *
     * @return array{total: int, kind_20: int, kind_21: int, kind_22: int, kind_34235: int, kind_34236: int}
     */
    private function getCounts(Connection $conn, int $cutoffTimestamp): array
    {
        $total = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM event WHERE kind IN (20, 21, 22, 34235, 34236) AND created_at < ?',
            [$cutoffTimestamp]
        );

        $kind20 = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM event WHERE kind = 20 AND created_at < ?',
            [$cutoffTimestamp]
        );

        $kind21 = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM event WHERE kind = 21 AND created_at < ?',
            [$cutoffTimestamp]
        );

        $kind22 = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM event WHERE kind = 22 AND created_at < ?',
            [$cutoffTimestamp]
        );

        $kind34235 = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM event WHERE kind = 34235 AND created_at < ?',
            [$cutoffTimestamp]
        );

        $kind34236 = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM event WHERE kind = 34236 AND created_at < ?',
            [$cutoffTimestamp]
        );

        return [
            'total' => $total,
            'kind_20' => $kind20,
            'kind_21' => $kind21,
            'kind_22' => $kind22,
            'kind_34235' => $kind34235,
            'kind_34236' => $kind34236,
        ];
    }

    /**
     * Delete old media events.
     */
    private function deleteOldMediaEvents(Connection $conn, int $cutoffTimestamp): int
    {
        return $conn->executeStatement(
            'DELETE FROM event WHERE kind IN (20, 21, 22, 34235, 34236) AND created_at < ?',
            [$cutoffTimestamp]
        );
    }
}

