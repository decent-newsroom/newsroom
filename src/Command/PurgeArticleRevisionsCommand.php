<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
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
 * Hard-deletes older Article revisions per (pubkey, kind, slug) coordinate.
 *
 * Unlike `articles:deduplicate` (which only flags rows DO_NOT_INDEX so a
 * subsequent `db:cleanup` can remove them through the ORM), this command
 * removes the rows directly via Doctrine `EntityManager::remove()` so the
 * FOS Elastica Doctrine listener fires `postRemove` on each deleted row
 * and evicts the corresponding document from Elasticsearch in lockstep.
 * That is the only reliable way to keep Postgres and ES in sync after
 * historical revision overflow has accumulated (e.g. before the projector
 * gained its NIP-01 ordering guard).
 *
 * The command is idempotent: re-running it on a clean DB is a no-op.
 *
 * Selection criteria (per coordinate, NIP-01 replaceability):
 *   - Newest row by `created_at` is kept.
 *   - On equal `created_at`, the lexicographically lower `event_id` is kept.
 *   - Rows missing pubkey, slug or kind are ignored (cannot be deduped).
 *   - Kinds 30023 and 30024 are NOT collapsed against each other (different
 *     replaceable addresses).
 */
#[AsCommand(
    name: 'articles:purge-revisions',
    description: 'Hard-delete older revisions per (pubkey, kind, slug) via ORM remove (keeps Elasticsearch in sync).',
)]
class PurgeArticleRevisionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'How many coordinates to process per batch.', '200')
            ->addOption('sleep-ms', null, InputOption::VALUE_REQUIRED, 'Pause between batches (ms).', '100')
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Cap total number of batches (0 = unlimited).', '0')
            ->addOption('pubkey', null, InputOption::VALUE_REQUIRED, 'Only purge revisions for this pubkey (hex).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count rows that would be removed and exit without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $sleepMs = max(0, (int) $input->getOption('sleep-ms'));
        $maxBatches = max(0, (int) $input->getOption('max-batches'));
        $pubkey = $input->getOption('pubkey');
        $dryRun = (bool) $input->getOption('dry-run');

        // Build the list of coordinates that have more than one row. We do
        // this in a single SQL aggregation so we don't sweep the entire
        // article table through the ORM. For each such coordinate the per-
        // coordinate work loads only the rows for that coordinate (typically
        // 2-10) and operates through the ORM.
        $sql = 'SELECT pubkey, kind, slug, COUNT(*) AS cnt
                FROM article
                WHERE pubkey IS NOT NULL AND kind IS NOT NULL AND slug IS NOT NULL';
        $params = [];
        if (is_string($pubkey) && $pubkey !== '') {
            $sql .= ' AND pubkey = :pubkey';
            $params['pubkey'] = $pubkey;
        }
        $sql .= ' GROUP BY pubkey, kind, slug HAVING COUNT(*) > 1 ORDER BY cnt DESC';

        $coordinates = $this->connection->fetchAllAssociative($sql, $params);
        $totalCoords = count($coordinates);

        if ($totalCoords === 0) {
            $io->success('No coordinates with multiple revisions found. Nothing to do.');
            return Command::SUCCESS;
        }

        $totalRedundant = array_sum(array_map(static fn(array $r) => (int) $r['cnt'] - 1, $coordinates));

        $io->writeln(sprintf(
            'Found <info>%d</info> coordinate(s) with multiple revisions — <info>%d</info> redundant row(s) to remove.',
            $totalCoords,
            $totalRedundant,
        ));

        if ($dryRun) {
            $io->success(sprintf('Dry run: would remove %d row(s) across %d coordinate(s).', $totalRedundant, $totalCoords));
            return Command::SUCCESS;
        }

        $repo = $this->em->getRepository(Article::class);
        $removed = 0;
        $coordsDone = 0;
        $batchesDone = 0;
        $progress = $io->createProgressBar($totalCoords);
        $progress->start();

        foreach (array_chunk($coordinates, $batchSize) as $batch) {
            foreach ($batch as $row) {
                $kindValue = (int) $row['kind'];
                $kind = \App\Enum\KindsEnum::tryFrom($kindValue);
                if ($kind === null) {
                    // Unknown kind value; skip rather than risk an enum cast error.
                    $coordsDone++;
                    $progress->advance();
                    continue;
                }

                /** @var Article[] $revisions */
                $revisions = $repo->findAllRevisionsByCoordinate($row['pubkey'], $kind, $row['slug']);
                if (count($revisions) <= 1) {
                    $coordsDone++;
                    $progress->advance();
                    continue;
                }

                // findAllRevisionsByCoordinate already orders by NIP-01 winner
                // first (createdAt DESC, eventId ASC). Keep [0], remove the rest.
                $kept = $revisions[0];
                for ($i = 1, $n = count($revisions); $i < $n; $i++) {
                    $this->em->remove($revisions[$i]);
                    $removed++;
                }

                try {
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to purge revisions for coordinate', [
                        'pubkey' => substr($row['pubkey'], 0, 16) . '...',
                        'kind' => $kindValue,
                        'slug' => $row['slug'],
                        'error' => $e->getMessage(),
                    ]);
                    // Reset to recover from a half-applied unit of work.
                    $this->em->clear();
                }

                $coordsDone++;
                $progress->advance();
            }

            // Detach managed entities to keep memory bounded across batches.
            $this->em->clear();

            $batchesDone++;
            if ($maxBatches > 0 && $batchesDone >= $maxBatches) {
                break;
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $progress->finish();
        $io->newLine(2);

        $io->success(sprintf(
            'Removed %d redundant article revision(s) across %d coordinate(s) in %d batch(es).',
            $removed,
            $coordsDone,
            $batchesDone,
        ));

        return Command::SUCCESS;
    }
}

