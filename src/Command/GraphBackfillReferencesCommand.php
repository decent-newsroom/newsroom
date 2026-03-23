<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Graph\ReferenceParserService;
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
 * One-time backfill of the parsed_reference table from existing events.
 *
 * Phase 1 scope: only `a` tags are parsed.
 * Processes events in batches to avoid memory issues on large tables.
 */
#[AsCommand(
    name: 'dn:graph:backfill-references',
    description: 'Backfill parsed_reference table from existing events (a tags only)',
)]
class GraphBackfillReferencesCommand extends Command
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly ReferenceParserService $referenceParser,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Batch size for processing', self::BATCH_SIZE)
            ->addOption('truncate', null, InputOption::VALUE_NONE, 'Truncate parsed_reference table before backfill')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = (int) $input->getOption('batch-size');

        $io->title('Backfill parsed_reference table');

        if ($input->getOption('truncate')) {
            $io->warning('Truncating parsed_reference table...');
            $this->connection->executeStatement('TRUNCATE TABLE parsed_reference');
        }

        // Count events that have `a` tags — use JSONB containment to leverage the GIN index
        $totalCount = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM event WHERE tags @> :pattern",
            ['pattern' => '[["a"]]'],
        );

        if ($totalCount === 0) {
            $io->success('No events with a tags found.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d events with potential a tags to process.', $totalCount));
        $io->progressStart($totalCount);

        $offset = 0;
        $totalRefs = 0;
        $processedEvents = 0;

        while ($offset < $totalCount) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, kind, pubkey, tags FROM event WHERE tags @> :pattern ORDER BY id LIMIT :limit OFFSET :offset",
                ['pattern' => '[["a"]]', 'limit' => $batchSize, 'offset' => $offset],
                ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
            );

            if (empty($rows)) {
                break;
            }

            $batchRefs = [];
            $batchEventIds = [];

            foreach ($rows as $row) {
                $tags = is_string($row['tags']) ? json_decode($row['tags'], true) : $row['tags'];
                if (!is_array($tags)) {
                    continue;
                }

                $refs = $this->referenceParser->parseFromTagsArray(
                    $row['id'],
                    (int) $row['kind'],
                    $tags,
                );

                if (!empty($refs)) {
                    $batchEventIds[] = $row['id'];
                    foreach ($refs as $ref) {
                        $batchRefs[] = $ref;
                    }
                }

                $processedEvents++;
                $io->progressAdvance();
            }

            // Delete existing references for this batch (idempotent re-runs)
            if (!empty($batchEventIds)) {
                $placeholders = implode(',', array_fill(0, count($batchEventIds), '?'));
                $this->connection->executeStatement(
                    "DELETE FROM parsed_reference WHERE source_event_id IN ({$placeholders})",
                    array_values($batchEventIds),
                );
            }

            // Bulk insert references
            if (!empty($batchRefs)) {
                $this->bulkInsertReferences($batchRefs);
                $totalRefs += count($batchRefs);
            }

            $offset += $batchSize;
        }

        $io->progressFinish();

        $io->success(sprintf(
            'Backfill complete: processed %d events, inserted %d references.',
            $processedEvents,
            $totalRefs,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param \App\Service\Graph\ParsedReferenceDto[] $refs
     */
    private function bulkInsertReferences(array $refs): void
    {
        $sql = <<<'SQL'
            INSERT INTO parsed_reference
                (source_event_id, tag_name, target_ref_type, target_kind, target_pubkey, target_d_tag, target_coord, relation, marker, position, is_structural, is_resolvable)
            VALUES
        SQL;

        $values = [];
        $params = [];
        $types = [];
        $i = 0;

        foreach ($refs as $ref) {
            $values[] = sprintf(
                '(:sei%d, :tn%d, :trt%d, :tk%d, :tp%d, :td%d, :tc%d, :rel%d, :mk%d, :pos%d, :is%d, :ir%d)',
                $i, $i, $i, $i, $i, $i, $i, $i, $i, $i, $i, $i
            );
            $params["sei{$i}"] = $ref->sourceEventId;
            $params["tn{$i}"] = $ref->tagName;
            $params["trt{$i}"] = $ref->targetRefType;
            $params["tk{$i}"] = $ref->targetKind;
            $params["tp{$i}"] = $ref->targetPubkey;
            $params["td{$i}"] = $ref->targetDTag;
            $params["tc{$i}"] = $ref->targetCoord;
            $params["rel{$i}"] = $ref->relation;
            $params["mk{$i}"] = $ref->marker;
            $params["pos{$i}"] = $ref->position;
            $params["is{$i}"] = $ref->isStructural;
            $types["is{$i}"] = ParameterType::BOOLEAN;
            $params["ir{$i}"] = $ref->isResolvable;
            $types["ir{$i}"] = ParameterType::BOOLEAN;
            $i++;
        }

        $fullSql = $sql . "\n" . implode(",\n", $values);

        try {
            $this->connection->executeStatement($fullSql, $params, $types);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to bulk insert references', [
                'count' => count($refs),
                'error' => $e->getMessage(),
            ]);
            // Fall back to individual inserts
            foreach ($refs as $ref) {
                try {
                    $this->connection->executeStatement(
                        'INSERT INTO parsed_reference (source_event_id, tag_name, target_ref_type, target_kind, target_pubkey, target_d_tag, target_coord, relation, marker, position, is_structural, is_resolvable) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $ref->sourceEventId, $ref->tagName, $ref->targetRefType,
                            $ref->targetKind, $ref->targetPubkey, $ref->targetDTag,
                            $ref->targetCoord, $ref->relation, $ref->marker,
                            $ref->position, $ref->isStructural, $ref->isResolvable,
                        ],
                        [
                            ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                            ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING,
                            ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                            ParameterType::INTEGER, ParameterType::BOOLEAN, ParameterType::BOOLEAN,
                        ]
                    );
                } catch (\Throwable $inner) {
                    $this->logger->warning('Failed to insert single reference', [
                        'source' => $ref->sourceEventId,
                        'target' => $ref->targetCoord,
                        'error' => $inner->getMessage(),
                    ]);
                }
            }
        }
    }
}



