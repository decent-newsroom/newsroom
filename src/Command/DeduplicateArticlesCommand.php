<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\IndexStatusEnum;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deduplicate parameterized-replaceable articles by Nostr coordinate
 * (kind:pubkey:d-tag, stored as kind + pubkey + slug).
 *
 * For each (pubkey, slug, kind) tuple the newest row (by created_at, id) is
 * kept; older rows are flagged as DO_NOT_INDEX so that `db:cleanup` can
 * remove them through the ORM — which fires the FOS Elastica Doctrine
 * listener and evicts the stale document from Elasticsearch at the same
 * time. Raw-SQL deletion is intentionally NOT offered here because it
 * would bypass the listener and leave ES out of sync.
 *
 * The work is done in Postgres via a window function, in bounded batches
 * (LIMIT), so the transaction / lock footprint stays small and the command
 * can be interrupted and resumed safely.
 */
#[AsCommand(name: 'articles:deduplicate', description: 'Flag older duplicates of articles (same pubkey+slug+kind) as DO_NOT_INDEX.')]
class DeduplicateArticlesCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Rows processed per batch.', '1000')
            ->addOption('sleep-ms', null, InputOption::VALUE_REQUIRED, 'Milliseconds to sleep between batches.', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report how many duplicates would be flagged and exit.')
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Stop after this many batches (0 = unlimited).', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $batchSize  = max(1, (int) $input->getOption('batch-size'));
        $sleepUs    = max(0, (int) $input->getOption('sleep-ms')) * 1000;
        $dryRun     = (bool) $input->getOption('dry-run');
        $maxBatches = max(0, (int) $input->getOption('max-batches'));

        $doNotIndex = IndexStatusEnum::DO_NOT_INDEX->value;

        $io->title('Article deduplication');
        $io->writeln(sprintf(
            'Mode: <info>FLAG DO_NOT_INDEX</info> — batch size: <info>%d</info> — sleep: <info>%d ms</info>%s',
            $batchSize,
            (int) $input->getOption('sleep-ms'),
            $dryRun ? ' — <comment>DRY RUN</comment>' : ''
        ));
        $io->writeln('Run <info>bin/console db:cleanup</info> afterwards to remove flagged rows via the ORM (keeps Elasticsearch in sync).');

        if ($dryRun) {
            $count = (int) $this->connection->fetchOne(
                <<<SQL
                SELECT COUNT(*) FROM (
                    SELECT id,
                           ROW_NUMBER() OVER (
                               PARTITION BY pubkey, slug, kind
                               ORDER BY created_at DESC NULLS LAST, id DESC
                           ) AS rn
                    FROM article
                    WHERE pubkey IS NOT NULL AND slug IS NOT NULL AND kind IS NOT NULL
                ) t
                WHERE rn > 1
                SQL
            );
            $io->success(sprintf('Would flag %d duplicate article(s) as DO_NOT_INDEX.', $count));
            return Command::SUCCESS;
        }

        // Skip rows already flagged so batches keep making progress and the
        // command is idempotent across repeated runs.
        $sql = <<<SQL
            UPDATE article SET index_status = :doNotIndex
            WHERE id IN (
                SELECT id FROM (
                    SELECT id,
                           ROW_NUMBER() OVER (
                               PARTITION BY pubkey, slug, kind
                               ORDER BY created_at DESC NULLS LAST, id DESC
                           ) AS rn
                    FROM article
                    WHERE pubkey IS NOT NULL AND slug IS NOT NULL AND kind IS NOT NULL
                      AND (index_status IS NULL OR index_status <> :doNotIndex)
                ) t
                WHERE rn > 1
                LIMIT :batch
            )
            SQL;

        $total = 0;
        $batch = 0;

        while (true) {
            $affected = (int) $this->connection->executeStatement($sql, [
                'doNotIndex' => $doNotIndex,
                'batch'      => $batchSize,
            ]);
            if ($affected === 0) {
                break;
            }

            $total += $affected;
            $batch++;
            $io->writeln(sprintf('  Batch %d: flagged %d row(s) (running total: %d)', $batch, $affected, $total));

            if ($maxBatches > 0 && $batch >= $maxBatches) {
                $io->note(sprintf('Stopped after --max-batches=%d.', $maxBatches));
                break;
            }

            if ($sleepUs > 0) {
                usleep($sleepUs);
            }
        }

        $io->success(sprintf('Flagged %d duplicate article(s) as DO_NOT_INDEX across %d batch(es).', $total, $batch));

        if ($total > 0) {
            $io->writeln('Run <info>bin/console db:cleanup</info> to actually remove the flagged rows (and their Elasticsearch documents).');
        }

        return Command::SUCCESS;
    }

}
