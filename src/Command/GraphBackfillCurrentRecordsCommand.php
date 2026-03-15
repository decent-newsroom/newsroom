<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Graph\CurrentVersionResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-time backfill of the current_record table from existing events.
 *
 * Processes all replaceable and parameterized replaceable events,
 * applying the newest-wins rule with event-id tie-break.
 *
 * Events are processed in created_at ASC order so that for each coordinate,
 * the latest version naturally wins via the upsert logic.
 */
#[AsCommand(
    name: 'dn:graph:backfill-current-records',
    description: 'Backfill current_record table from existing replaceable/parameterized-replaceable events',
)]
class GraphBackfillCurrentRecordsCommand extends Command
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly Connection $connection,
        private readonly CurrentVersionResolver $currentVersionResolver,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Batch size for processing', self::BATCH_SIZE)
            ->addOption('truncate', null, InputOption::VALUE_NONE, 'Truncate current_record table before backfill')
            ->addOption('kinds', 'k', InputOption::VALUE_OPTIONAL, 'Comma-separated list of kinds to process (default: all replaceable)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = (int) $input->getOption('batch-size');

        $io->title('Backfill current_record table');

        if ($input->getOption('truncate')) {
            $io->warning('Truncating current_record table...');
            $this->connection->executeStatement('TRUNCATE TABLE current_record');
        }

        // Build the WHERE clause for replaceable kinds
        $kindsOption = $input->getOption('kinds');
        $whereClause = $this->buildWhereClause($kindsOption);

        // Count total events to process
        $totalCount = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM event WHERE {$whereClause}"
        );

        if ($totalCount === 0) {
            $io->success('No replaceable events found.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d replaceable events to process.', $totalCount));
        $io->progressStart($totalCount);

        $offset = 0;
        $processed = 0;
        $updated = 0;

        // Process in created_at ASC order so newest naturally wins
        while ($offset < $totalCount) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, kind, pubkey, created_at, d_tag FROM event WHERE {$whereClause} ORDER BY created_at ASC LIMIT :limit OFFSET :offset",
                ['limit' => $batchSize, 'offset' => $offset],
                ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $becameCurrent = $this->currentVersionResolver->updateIfCurrent(
                    eventId: $row['id'],
                    kind: (int) $row['kind'],
                    pubkey: $row['pubkey'],
                    dTag: $row['d_tag'],
                    createdAt: (int) $row['created_at'],
                );

                if ($becameCurrent) {
                    $updated++;
                }

                $processed++;
                $io->progressAdvance();
            }

            $offset += $batchSize;
        }

        $io->progressFinish();

        $this->logger->info('current_record backfill from event table complete', [
            'processed' => $processed,
            'updated' => $updated,
        ]);

        $io->success(sprintf(
            'Event table pass: processed %d events, %d current records created/updated.',
            $processed,
            $updated,
        ));

        // ── Second pass: article table ─────────────────────────────────────
        // Articles may not exist in the event table but have their own entity
        // with event_id, kind, pubkey, slug (d-tag), and created_at.
        $io->section('Backfilling from article table');

        $articleCount = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM article WHERE event_id IS NOT NULL AND kind IS NOT NULL AND pubkey IS NOT NULL"
        );

        if ($articleCount === 0) {
            $io->info('No articles with event IDs found.');
        } else {
            $io->info(sprintf('Found %d articles to process.', $articleCount));
            $io->progressStart($articleCount);

            $artOffset = 0;
            $artProcessed = 0;
            $artUpdated = 0;

            while ($artOffset < $articleCount) {
                $artRows = $this->connection->fetchAllAssociative(
                    "SELECT event_id, kind, pubkey, slug, EXTRACT(EPOCH FROM created_at)::bigint AS created_at_ts FROM article WHERE event_id IS NOT NULL AND kind IS NOT NULL AND pubkey IS NOT NULL ORDER BY created_at ASC LIMIT :limit OFFSET :offset",
                    ['limit' => $batchSize, 'offset' => $artOffset],
                    ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
                );

                if (empty($artRows)) {
                    break;
                }

                foreach ($artRows as $artRow) {
                    $becameCurrent = $this->currentVersionResolver->updateIfCurrent(
                        eventId: $artRow['event_id'],
                        kind: (int) $artRow['kind'],
                        pubkey: $artRow['pubkey'],
                        dTag: $artRow['slug'],
                        createdAt: (int) $artRow['created_at_ts'],
                    );

                    if ($becameCurrent) {
                        $artUpdated++;
                    }

                    $artProcessed++;
                    $io->progressAdvance();
                }

                $artOffset += $batchSize;
            }

            $io->progressFinish();

            $this->logger->info('current_record backfill from article table complete', [
                'processed' => $artProcessed,
                'updated' => $artUpdated,
            ]);

            $io->success(sprintf(
                'Article table pass: processed %d articles, %d current records created/updated.',
                $artProcessed,
                $artUpdated,
            ));
        }

        $io->success(sprintf(
            'Full backfill complete. Total: %d events + %d articles processed.',
            $processed,
            $articleCount,
        ));

        return Command::SUCCESS;
    }

    private function buildWhereClause(?string $kindsOption): string
    {
        if ($kindsOption !== null) {
            $kinds = array_map('intval', explode(',', $kindsOption));
            $kindsList = implode(',', $kinds);
            return "kind IN ({$kindsList})";
        }

        // Default: all replaceable events
        // Parameterized replaceable: 30000–39999
        // Replaceable: 0, 3, 10000–19999
        return '(kind >= 30000 AND kind <= 39999) OR kind IN (0, 3) OR (kind >= 10000 AND kind <= 19999)';
    }
}

