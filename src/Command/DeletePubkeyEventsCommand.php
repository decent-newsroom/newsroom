<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Nostr\NostrKeyUtil;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bulk delete all events from a pubkey with cascade cleanup.
 *
 * Much faster than NIP-09 deletion requests for large-scale removal
 * (spam, abuse, account deletion).
 *
 * Usage:
 *   docker compose exec php bin/console admin:delete-pubkey-events <pubkey|npub>
 *   docker compose exec php bin/console admin:delete-pubkey-events <pubkey> --exclude-kinds=0,3 --confirm
 *   docker compose exec php bin/console admin:delete-pubkey-events <pubkey> --dry-run
 *
 * Performance:
 *   - 10,000 events: ~5-15 seconds
 *   - 100,000 events: ~30-60 seconds
 *   - With cascading deletes (Article, Highlight, Magazine)
 */
#[AsCommand(
    name: 'admin:delete-pubkey-events',
    description: 'Bulk delete all events from a pubkey with cascade cleanup (faster than NIP-09 for large-scale removal)',
)]
class DeletePubkeyEventsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pubkey', InputArgument::REQUIRED, 'Hex or npub-encoded pubkey')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview counts without deleting')
            ->addOption('exclude-kinds', null, InputOption::VALUE_OPTIONAL, 'Comma-separated kinds to preserve (e.g., 0,3,10002)')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Skip confirmation prompt (dangerous!)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pubkeyInput = $input->getArgument('pubkey');
        $dryRun = $input->getOption('dry-run');
        $excludeKindsStr = $input->getOption('exclude-kinds') ?? '';
        $confirm = $input->getOption('confirm');

        // Convert npub to hex if needed
        $pubkey = $this->normalizePubkey($pubkeyInput);
        if (! $pubkey) {
            $io->error(sprintf('Invalid pubkey format: %s (expected 64 hex chars or npub)', $pubkeyInput));
            return Command::FAILURE;
        }

        // Parse excluded kinds
        $excludeKinds = array_filter(
            array_map('intval', explode(',', trim($excludeKindsStr)))
        );
        if ($excludeKinds) {
            $io->note(sprintf('Will preserve events with kinds: %s', implode(', ', $excludeKinds)));
        }

        $conn = $this->em->getConnection();

        // Get detailed counts
        $counts = $this->getCounts($conn, $pubkey, $excludeKinds);

        if ($counts['total'] === 0) {
            $io->info(sprintf('No events found for pubkey %s', substr($pubkey, 0, 12)));
            return Command::SUCCESS;
        }

        // Show what will be deleted
        $io->section('Deletion Summary');
        $io->listing([
            sprintf('Total events: %d', $counts['total']),
            sprintf('  • Articles (30023/30024): %d', $counts['articles']),
            sprintf('  • Highlights (9802): %d', $counts['highlights']),
            sprintf('  • Magazines (30040/30041): %d', $counts['magazines']),
            sprintf('  • Other events: %d', $counts['other']),
        ]);

        if ($dryRun) {
            $io->success('[DRY RUN] No changes made.');
            return Command::SUCCESS;
        }

        // Confirm dangerous action
        if (! $confirm) {
            $io->warning(sprintf(
                'This will PERMANENTLY DELETE %d event(s) from %s',
                $counts['total'],
                substr($pubkey, 0, 12),
            ));
            if (! $io->confirm('Are you absolutely sure?', false)) {
                $io->info('Aborted.');
                return Command::SUCCESS;
            }
        }

        // Execute deletion
        $io->section('Deleting...');
        $progress = $io->createProgressBar(4);
        $progress->setFormat('%current%/%max% [%bar%] %message%');
        $progress->start();

        try {
            $deletedArticles = $this->deleteFromTable($conn, 'article', $pubkey, $excludeKinds);
            $progress->setMessage('Articles deleted');
            $progress->advance();

            $deletedHighlights = $this->deleteFromTable($conn, 'highlight', $pubkey, $excludeKinds);
            $progress->setMessage('Highlights deleted');
            $progress->advance();

            $deletedMagazines = $this->deleteFromTable($conn, 'magazine', $pubkey, $excludeKinds);
            $progress->setMessage('Magazines deleted');
            $progress->advance();

            $deletedEvents = $this->deleteFromTable($conn, 'event', $pubkey, $excludeKinds);
            $progress->setMessage('Events deleted');
            $progress->advance();

            $progress->finish();
            $io->newLine(2);

            // Log the operation
            $this->logger->warning('admin:delete-pubkey-events executed', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'deleted_events' => $deletedEvents,
                'deleted_articles' => $deletedArticles,
                'deleted_highlights' => $deletedHighlights,
                'deleted_magazines' => $deletedMagazines,
                'excluded_kinds' => $excludeKinds,
            ]);

            $io->success(sprintf(
                'Deleted %d event(s) with cascade cleanup (articles: %d, highlights: %d, magazines: %d)',
                $deletedEvents,
                $deletedArticles,
                $deletedHighlights,
                $deletedMagazines,
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $progress->finish();
            $io->error(sprintf('Deletion failed: %s', $e->getMessage()));
            $this->logger->error('admin:delete-pubkey-events failed', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'error' => $e->getMessage(),
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Convert npub or hex to hex pubkey.
     */
    private function normalizePubkey(string $input): ?string
    {
        $trimmed = trim($input);

        // Already hex
        if (ctype_xdigit($trimmed) && strlen($trimmed) === 64) {
            return $trimmed;
        }

        // Try to decode npub
        if (str_starts_with($trimmed, 'npub1')) {
            try {
                return NostrKeyUtil::npubToHex($trimmed);
            } catch (\Throwable) {
                // Fall through to error
                return null;
            }
        }

        return null;
    }

    /**
     * Get counts of events and related data.
     *
     * @param int[] $excludeKinds
     * @return array{total: int, articles: int, highlights: int, magazines: int, other: int}
     */
    private function getCounts(Connection $conn, string $pubkey, array $excludeKinds): array
    {
        $kindExclude = $excludeKinds ? 'AND kind NOT IN (' . implode(',', $excludeKinds) . ')' : '';

        $total = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM event WHERE pubkey = ? {$kindExclude}",
            [$pubkey]
        );

        $articles = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM article WHERE pubkey = ?",
            [$pubkey]
        );

        $highlights = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM highlight WHERE pubkey = ?",
            [$pubkey]
        );

        $magazines = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM magazine WHERE pubkey = ?",
            [$pubkey]
        );

        return [
            'total' => $total,
            'articles' => $articles,
            'highlights' => $highlights,
            'magazines' => $magazines,
            'other' => $total - $articles - $highlights - $magazines,
        ];
    }

    /**
     * Delete rows from a table by pubkey.
     *
     * @param int[] $excludeKinds
     */
    private function deleteFromTable(
        Connection $conn,
        string $table,
        string $pubkey,
        array $excludeKinds
    ): int {
        $sql = "DELETE FROM {$table} WHERE pubkey = ?";
        $params = [$pubkey];

        // Only apply kind filtering to the event table
        if ($table === 'event' && $excludeKinds) {
            $sql .= ' AND kind NOT IN (' . implode(',', $excludeKinds) . ')';
        }

        return $conn->executeStatement($sql, $params);
    }
}


