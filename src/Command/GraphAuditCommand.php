<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Graph\CurrentVersionResolver;
use App\Service\Graph\ReferenceParserService;
use Doctrine\DBAL\ArrayParameterType;
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
 * Cron-safe consistency check for the graph layer.
 *
 * Detects drift between the relational source-of-truth (event/article tables)
 * and the derived graph tables (current_record, parsed_reference).
 *
 * Checks:
 *  1. current_record freshness — is the stored current_event_id actually the newest?
 *  2. parsed_reference completeness — are reference rows present for all events with a-tags?
 *  3. Orphan detection — current_record entries whose event no longer exists.
 */
#[AsCommand(
    name: 'dn:graph:audit',
    description: 'Audit graph layer consistency (current_record + parsed_reference vs event/article tables)',
)]
class GraphAuditCommand extends Command
{
    private const BATCH_SIZE = 500;
    private const MIN_FRESHNESS_CHUNK_SIZE = 25;

    /** @var int Per-query statement timeout in seconds (0 = no timeout) */
    private const QUERY_TIMEOUT_SECONDS = 120;

    private bool $interrupted = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly CurrentVersionResolver $currentVersionResolver,
        private readonly ReferenceParserService $referenceParser,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fix-versions', null, InputOption::VALUE_NONE, 'Auto-repair stale current_record entries')
            ->addOption('fix-references', null, InputOption::VALUE_NONE, 'Auto-repair missing/stale parsed_reference rows')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Auto-repair all detected issues (shorthand for --fix-versions --fix-references)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max coordinates to check (0 = all)', '0')
        ;
    }

    public function getSubscribedSignals(): array
    {
        return [\SIGINT, \SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->interrupted = true;

        return false; // let execute() finish its current iteration and exit cleanly
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fixAll = $input->getOption('fix');
        $fixVersions = $fixAll || $input->getOption('fix-versions');
        $fixReferences = $fixAll || $input->getOption('fix-references');
        $limit = (int) $input->getOption('limit');

        // Install PCNTL signal handler for graceful Ctrl+C
        if (\function_exists('pcntl_signal')) {
            \pcntl_signal(\SIGINT, function () {
                $this->interrupted = true;
            });
            \pcntl_signal(\SIGTERM, function () {
                $this->interrupted = true;
            });
        }

        $io->title('Graph Layer Audit');

        // Set a session-level statement timeout so no single query can stall forever
        $this->setStatementTimeout(self::QUERY_TIMEOUT_SECONDS);

        // ── Check 1: current_record freshness ──────────────────────────────
        $io->section('1. Current-record freshness');
        $staleRecords = $this->auditCurrentRecordFreshness($io, $limit);

        if ($this->interrupted) {
            $io->warning('Interrupted by signal.');
            return Command::FAILURE;
        }

        if ($fixVersions && !empty($staleRecords)) {
            $io->info(sprintf('Repairing %d stale current_record entries...', count($staleRecords)));
            $repaired = $this->repairCurrentRecords($staleRecords);
            $io->success(sprintf('Repaired %d / %d entries.', $repaired, count($staleRecords)));
        }

        // ── Check 2: parsed_reference completeness ─────────────────────────
        $io->section('2. Parsed-reference completeness');
        $refIssues = $this->auditParsedReferences($io, $limit);

        if ($this->interrupted) {
            $io->warning('Interrupted by signal.');
            return Command::FAILURE;
        }

        if ($fixReferences && !empty($refIssues)) {
            $io->info(sprintf('Repairing %d events with stale/missing references...', count($refIssues)));
            $repaired = $this->repairReferences($refIssues, $io);
            $io->success(sprintf('Repaired %d / %d events.', $repaired, count($refIssues)));
        }

        if ($this->interrupted) {
            $io->warning('Interrupted by signal.');
            return Command::FAILURE;
        }

        // ── Check 3: orphan detection ──────────────────────────────────────
        $io->section('3. Orphan detection');
        $orphanCount = $this->auditOrphans($io, $fixVersions);

        // ── Summary ────────────────────────────────────────────────────────
        $io->section('Summary');
        $totalIssues = count($staleRecords) + count($refIssues) + $orphanCount;

        if ($totalIssues === 0) {
            $io->success('Graph layer is consistent. No issues found.');
        } else {
            $io->warning(sprintf(
                'Found %d issue(s): %d stale versions, %d reference mismatches, %d orphans.',
                $totalIssues,
                count($staleRecords),
                count($refIssues),
                $orphanCount,
            ));
        }

        $this->logger->info('Graph audit complete', [
            'stale_versions' => count($staleRecords),
            'ref_mismatches' => count($refIssues),
            'orphans' => $orphanCount,
            'fix_applied' => $fixVersions || $fixReferences,
        ]);

        // Reset statement timeout
        $this->setStatementTimeout(0);

        return $totalIssues === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Set PostgreSQL session-level statement timeout.
     */
    private function setStatementTimeout(int $seconds): void
    {
        try {
            $ms = $seconds * 1000;
            $this->connection->executeStatement("SET statement_timeout = {$ms}");
        } catch (\Throwable $e) {
            $this->logger->warning('Graph audit: could not set statement_timeout', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Dispatch pending signals (if pcntl is available).
     */
    private function dispatchSignals(): void
    {
        if (\function_exists('pcntl_signal_dispatch')) {
            \pcntl_signal_dispatch();
        }
    }

    /**
     * Check 1: For each current_record, verify that its current_event_id
     * matches the actual newest event for that coordinate in the event table.
     *
     * Processes in batches to avoid a single long-running LATERAL query over
     * the entire current_record table.
     *
     * @return array<array{coord: string, stored_event_id: string, actual_event_id: string, kind: int, pubkey: string, d_tag: ?string}>
     */
    private function auditCurrentRecordFreshness(SymfonyStyle $io, int $limit): array
    {
        // Count total current_record entries to process
        $totalRecords = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM current_record');
        $checkLimit = $limit > 0 ? $limit : $totalRecords;
        $targetRecords = min($checkLimit, $totalRecords);

        if ($totalRecords === 0) {
            $io->info('✓ No current_record entries to check.');
            return [];
        }

        $io->info(sprintf('Checking %d current_record entries in batches of up to %d…', $targetRecords, self::BATCH_SIZE));

        $stale = [];
        $cursor = '';
        $checked = 0;
        $io->progressStart($targetRecords);

        while ($checked < $targetRecords && !$this->interrupted) {
            $batchLimit = min(self::BATCH_SIZE, $targetRecords - $checked);

            try {
                $batchRows = $this->fetchCurrentRecordBatch($cursor, $batchLimit);

                if (empty($batchRows)) {
                    break;
                }

                $rows = $this->findStaleCurrentRecordsWithFallback($batchRows);

                foreach ($rows as $row) {
                    $stale[] = $row;
                }
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed near item %d: %s', $checked, $e->getMessage()));
                $this->logger->error('Graph audit: current_record freshness check failed', [
                    'checked' => $checked,
                    'cursor' => $cursor,
                    'batch_limit' => $batchLimit,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            $processed = count($batchRows);
            $checked += $processed;
            $io->progressAdvance($processed);

            $lastRow = $batchRows[array_key_last($batchRows)];
            $cursor = (string) $lastRow['record_uid'];

            $this->dispatchSignals();
        }

        $io->progressFinish();

        if (empty($stale)) {
            $io->info(sprintf('✓ All %d current_record entries are fresh.', $checked));
        } else {
            $io->warning(sprintf('Found %d stale current_record entries (checked %d).', count($stale), $checked));
            $sample = array_slice($stale, 0, 5);
            $rows = array_map(fn(array $r) => [
                $r['coord'],
                substr($r['stored_event_id'], 0, 16) . '…',
                substr($r['actual_event_id'], 0, 16) . '…',
                $r['stored_created_at'],
                $r['actual_created_at'],
            ], $sample);
            $io->table(['Coord', 'Stored Event', 'Actual Event', 'Stored TS', 'Actual TS'], $rows);
        }

        return $stale;
    }

    /**
     * @return array<int, array{record_uid: string, coord: string, stored_event_id: string, kind: int|string, pubkey: string, d_tag: ?string, stored_created_at: int|string}>
     */
    private function fetchCurrentRecordBatch(string $cursor, int $batchLimit): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT record_uid, coord, current_event_id AS stored_event_id, kind, pubkey, d_tag, current_created_at AS stored_created_at FROM current_record WHERE record_uid > :cursor ORDER BY record_uid ASC LIMIT :batch_limit',
            [
                'cursor' => $cursor,
                'batch_limit' => $batchLimit,
            ],
            [
                'cursor' => ParameterType::STRING,
                'batch_limit' => ParameterType::INTEGER,
            ],
        );
    }

    /**
     * Retry stale detection with smaller chunks when PostgreSQL cancels large batches.
     *
     * @param array<int, array{record_uid: string, coord: string, stored_event_id: string, kind: int|string, pubkey: string, d_tag: ?string, stored_created_at: int|string}> $batchRows
     * @return array<int, array<string, mixed>>
     */
    private function findStaleCurrentRecordsWithFallback(array $batchRows): array
    {
        try {
            return $this->findStaleCurrentRecordsForBatch($batchRows);
        } catch (\Throwable $e) {
            if (!$this->isStatementTimeoutException($e) || count($batchRows) <= self::MIN_FRESHNESS_CHUNK_SIZE) {
                throw $e;
            }

            $mid = intdiv(count($batchRows), 2);
            $left = array_slice($batchRows, 0, $mid);
            $right = array_slice($batchRows, $mid);

            $this->logger->warning('Graph audit: freshness batch timed out, splitting batch', [
                'batch_size' => count($batchRows),
                'left_size' => count($left),
                'right_size' => count($right),
            ]);

            return array_merge(
                $this->findStaleCurrentRecordsWithFallback($left),
                $this->findStaleCurrentRecordsWithFallback($right),
            );
        }
    }

    /**
     * @param array<int, array{record_uid: string, coord: string, stored_event_id: string, kind: int|string, pubkey: string, d_tag: ?string, stored_created_at: int|string}> $batchRows
     * @return array<int, array<string, mixed>>
     */
    private function findStaleCurrentRecordsForBatch(array $batchRows): array
    {
        if (empty($batchRows)) {
            return [];
        }

        $values = [];
        $params = [];
        $types = [];

        foreach ($batchRows as $i => $row) {
            $values[] = sprintf('(:coord%d, :stored_event_id%d, :kind%d, :pubkey%d, :d_tag%d, :stored_created_at%d)', $i, $i, $i, $i, $i, $i);

            $params["coord{$i}"] = $row['coord'];
            $types["coord{$i}"] = ParameterType::STRING;

            $params["stored_event_id{$i}"] = $row['stored_event_id'];
            $types["stored_event_id{$i}"] = ParameterType::STRING;

            $params["kind{$i}"] = (int) $row['kind'];
            $types["kind{$i}"] = ParameterType::INTEGER;

            $params["pubkey{$i}"] = $row['pubkey'];
            $types["pubkey{$i}"] = ParameterType::STRING;

            $params["d_tag{$i}"] = $row['d_tag'];
            $types["d_tag{$i}"] = $row['d_tag'] === null ? ParameterType::NULL : ParameterType::STRING;

            $params["stored_created_at{$i}"] = (string) $row['stored_created_at'];
            $types["stored_created_at{$i}"] = ParameterType::STRING;
        }

        $sql = <<<SQL
            WITH batch (coord, stored_event_id, kind, pubkey, d_tag, stored_created_at) AS (
                VALUES
                %s
            ),
            newest AS (
                SELECT DISTINCT ON (e.kind, e.pubkey, e.d_tag)
                    e.kind,
                    e.pubkey,
                    e.d_tag,
                    e.id,
                    e.created_at
                FROM event e
                INNER JOIN batch b
                    ON e.kind = b.kind
                   AND e.pubkey = b.pubkey
                   AND e.d_tag IS NOT DISTINCT FROM b.d_tag
                ORDER BY e.kind, e.pubkey, e.d_tag, e.created_at DESC, e.id ASC
            )
            SELECT
                b.coord,
                b.stored_event_id,
                b.kind,
                b.pubkey,
                b.d_tag,
                n.id AS actual_event_id,
                n.created_at AS actual_created_at,
                b.stored_created_at
            FROM batch b
            INNER JOIN newest n
                ON n.kind = b.kind
               AND n.pubkey = b.pubkey
               AND n.d_tag IS NOT DISTINCT FROM b.d_tag
            WHERE n.id <> b.stored_event_id
        SQL;

        $fullSql = sprintf($sql, implode(",\n", $values));

        return $this->connection->fetchAllAssociative($fullSql, $params, $types);
    }

    private function isStatementTimeoutException(\Throwable $e): bool
    {
        $current = $e;

        while ($current !== null) {
            $message = $current->getMessage();
            $code = (string) $current->getCode();

            if (
                $code === '57014'
                || str_contains($message, 'SQLSTATE[57014]')
                || str_contains(strtolower($message), 'statement timeout')
            ) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    /**
     * Check 2: Sample events with `a` tags and verify parsed_reference count matches.
     *
     * Uses JSONB containment operator (@>) to leverage the GIN index instead of
     * casting to text. Batch-fetches stored counts to avoid N+1 queries.
     * Drops ORDER BY — we just need a sample, not the most recent ones.
     *
     * @return array<array{event_id: string, kind: int, expected: int, stored: int, tags: array}>
     */
    private function auditParsedReferences(SymfonyStyle $io, int $limit): array
    {
        $checkLimit = $limit > 0 ? $limit : 1000;

        $io->info(sprintf('Sampling up to %d events with a tags…', $checkLimit));

        // Use JSONB containment operator — hits the GIN index (jsonb_path_ops)
        // No ORDER BY: we just need a sample, sorting all matching rows is expensive
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, kind, tags FROM event WHERE tags @> :pattern LIMIT :lim",
                ['pattern' => '[["a"]]', 'lim' => $checkLimit],
                ['pattern' => ParameterType::STRING, 'lim' => ParameterType::INTEGER],
            );
        } catch (\Throwable $e) {
            $io->error('Failed to query events with a tags: ' . $e->getMessage());
            $this->logger->error('Graph audit: parsed_reference query failed', ['error' => $e->getMessage()]);
            return [];
        }

        if (empty($rows)) {
            $io->info('✓ No events with a tags found.');
            return [];
        }

        $io->info(sprintf('Fetched %d events, checking reference counts…', count($rows)));

        // Batch-fetch stored reference counts for all sampled events in one query
        $eventIds = array_column($rows, 'id');
        $storedCounts = $this->batchFetchReferenceCounts($eventIds);

        $issues = [];
        $io->progressStart(count($rows));

        foreach ($rows as $row) {
            if ($this->interrupted) {
                break;
            }

            $tags = is_string($row['tags']) ? json_decode($row['tags'], true) : $row['tags'];
            if (!is_array($tags)) {
                $io->progressAdvance();
                continue;
            }

            $expected = $this->referenceParser->parseFromTagsArray($row['id'], (int) $row['kind'], $tags);
            $expectedCount = count($expected);
            $storedCount = $storedCounts[$row['id']] ?? 0;

            if ($expectedCount !== $storedCount) {
                $issues[] = [
                    'event_id' => $row['id'],
                    'kind' => (int) $row['kind'],
                    'expected' => $expectedCount,
                    'stored' => $storedCount,
                    'tags' => $tags, // carry forward to avoid re-fetching during repair
                ];
            }

            $io->progressAdvance();
            $this->dispatchSignals();
        }

        $io->progressFinish();

        if (empty($issues)) {
            $io->info(sprintf('✓ Checked %d events — all parsed_reference rows match.', count($rows)));
        } else {
            $io->warning(sprintf('Found %d events with mismatched parsed_reference counts (checked %d).', count($issues), count($rows)));
            $sample = array_slice($issues, 0, 5);
            $tableRows = array_map(fn(array $r) => [
                substr($r['event_id'], 0, 16) . '…',
                $r['kind'],
                $r['expected'],
                $r['stored'],
            ], $sample);
            $io->table(['Event ID', 'Kind', 'Expected Refs', 'Stored Refs'], $tableRows);
        }

        return $issues;
    }

    /**
     * Fetch reference counts for a batch of event IDs in a single query.
     *
     * @param string[] $eventIds
     * @return array<string, int> eventId => count
     */
    private function batchFetchReferenceCounts(array $eventIds): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $counts = [];

        // Process in chunks to avoid exceeding parameter limits
        foreach (array_chunk($eventIds, self::BATCH_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->connection->fetchAllAssociative(
                "SELECT source_event_id, COUNT(*) AS cnt FROM parsed_reference WHERE source_event_id IN ({$placeholders}) GROUP BY source_event_id",
                array_values($chunk),
            );

            foreach ($rows as $row) {
                $counts[$row['source_event_id']] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    /**
     * Check 3: Find current_record entries whose current_event_id
     * doesn't exist in either event or article tables.
     */
    private function auditOrphans(SymfonyStyle $io, bool $fix): int
    {
        $io->info('Checking for orphaned current_record entries…');

        $orphans = [];
        $checked = 0;
        $cursor = '';

        $io->info(sprintf('Scanning current_record entries in batches of %d…', self::BATCH_SIZE));

        while (!$this->interrupted) {
            try {
                $batchRows = $this->connection->fetchAllAssociative(
                    'SELECT record_uid, coord, current_event_id, kind FROM current_record WHERE record_uid > :cursor ORDER BY record_uid ASC LIMIT :batch_limit',
                    [
                        'cursor' => $cursor,
                        'batch_limit' => self::BATCH_SIZE,
                    ],
                    [
                        'cursor' => ParameterType::STRING,
                        'batch_limit' => ParameterType::INTEGER,
                    ],
                );
            } catch (\Throwable $e) {
                $io->error('Failed to load current_record batch: ' . $e->getMessage());
                $this->logger->error('Graph audit: orphan batch load failed', [
                    'cursor' => $cursor,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            if (empty($batchRows)) {
                break;
            }

            $recordUids = array_column($batchRows, 'record_uid');
            $eventIds = array_values(array_unique(array_column($batchRows, 'current_event_id')));

            $existingEventIds = [];
            $existingArticleEventIds = [];

            try {
                $existingEventRows = $this->connection->fetchAllAssociative(
                    'SELECT id FROM event WHERE id IN (?)',
                    [$eventIds],
                    [ArrayParameterType::STRING],
                );
                foreach ($existingEventRows as $row) {
                    $existingEventIds[(string) $row['id']] = true;
                }

                $existingArticleRows = $this->connection->fetchAllAssociative(
                    'SELECT event_id FROM article WHERE event_id IN (?)',
                    [$eventIds],
                    [ArrayParameterType::STRING],
                );
                foreach ($existingArticleRows as $row) {
                    $existingArticleEventIds[(string) $row['event_id']] = true;
                }
            } catch (\Throwable $e) {
                $io->error('Failed to check orphaned events in batch: ' . $e->getMessage());
                $this->logger->error('Graph audit: orphan detection batch failed', [
                    'cursor' => $cursor,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            $batchOrphans = [];
            foreach ($batchRows as $row) {
                $eventId = (string) $row['current_event_id'];

                if (isset($existingEventIds[$eventId]) || isset($existingArticleEventIds[$eventId])) {
                    continue;
                }

                $batchOrphans[] = $row;
                $orphans[] = $row;
            }

            if ($fix && !empty($batchOrphans)) {
                try {
                    $deleted = $this->connection->executeStatement(
                        'DELETE FROM current_record WHERE record_uid IN (?)',
                        [array_values(array_column($batchOrphans, 'record_uid'))],
                        [ArrayParameterType::STRING],
                    );
                    $io->info(sprintf('Removed %d orphaned current_record entries from this batch.', $deleted));
                } catch (\Throwable $e) {
                    $io->error('Failed to delete orphaned current_record entries: ' . $e->getMessage());
                    $this->logger->error('Graph audit: orphan deletion failed', [
                        'cursor' => $cursor,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $checked += count($batchRows);
            $lastRow = $batchRows[array_key_last($batchRows)];
            $cursor = (string) $lastRow['record_uid'];

            $this->dispatchSignals();
        }

        if (empty($orphans)) {
            $io->info(sprintf('✓ No orphaned current_record entries found after checking %d records.', $checked));
            return 0;
        }

        $io->warning(sprintf('Found %d orphaned current_record entries (checked %d).', count($orphans), $checked));
        $sample = array_slice($orphans, 0, 5);
        $tableRows = array_map(fn(array $r) => [
            $r['coord'],
            substr($r['current_event_id'], 0, 16) . '…',
            $r['kind'],
        ], $sample);
        $io->table(['Coord', 'Missing Event ID', 'Kind'], $tableRows);


        return count($orphans);
    }

    /**
     * Repair stale current_record entries by re-running updateIfCurrent
     * with the actual newest event data.
     */
    private function repairCurrentRecords(array $staleRecords): int
    {
        $repaired = 0;

        foreach ($staleRecords as $record) {
            if ($this->interrupted) {
                break;
            }

            try {
                $becameCurrent = $this->currentVersionResolver->updateIfCurrent(
                    eventId: $record['actual_event_id'],
                    kind: (int) $record['kind'],
                    pubkey: $record['pubkey'],
                    dTag: $record['d_tag'],
                    createdAt: (int) $record['actual_created_at'],
                );

                if ($becameCurrent) {
                    $repaired++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Graph audit: failed to repair current_record', [
                    'coord' => $record['coord'],
                    'error' => $e->getMessage(),
                ]);
            }

            $this->dispatchSignals();
        }

        return $repaired;
    }

    /**
     * Repair missing/stale parsed_reference rows by re-parsing from event tags.
     *
     * Uses tags carried forward from the audit phase to avoid re-fetching events.
     * Processes in batches with bulk insert for performance.
     */
    private function repairReferences(array $issues, SymfonyStyle $io): int
    {
        $repaired = 0;
        $totalChunks = (int) ceil(count($issues) / self::BATCH_SIZE);
        $chunkNum = 0;

        foreach (array_chunk($issues, self::BATCH_SIZE) as $chunk) {
            if ($this->interrupted) {
                break;
            }

            $chunkNum++;
            $io->info(sprintf('Processing batch %d / %d (%d events)…', $chunkNum, $totalChunks, count($chunk)));

            $batchRefs = [];
            $batchEventIds = [];

            foreach ($chunk as $issue) {
                $eventId = $issue['event_id'];

                try {
                    // Prefer tags carried from audit phase; fall back to DB
                    $tags = $issue['tags'] ?? null;
                    $kind = $issue['kind'];

                    if ($tags === null) {
                        $row = $this->connection->fetchAssociative(
                            'SELECT id, kind, tags FROM event WHERE id = ?',
                            [$eventId],
                        );

                        if ($row === false) {
                            continue;
                        }

                        $tags = is_string($row['tags']) ? json_decode($row['tags'], true) : $row['tags'];
                        $kind = (int) $row['kind'];
                    }

                    if (!is_array($tags)) {
                        continue;
                    }

                    $refs = $this->referenceParser->parseFromTagsArray($eventId, $kind, $tags);
                    $batchEventIds[] = $eventId;

                    foreach ($refs as $ref) {
                        $batchRefs[] = $ref;
                    }

                    $repaired++;
                } catch (\Throwable $e) {
                    $this->logger->warning('Graph audit: failed to parse references for repair', [
                        'event_id' => $eventId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Batch delete existing references
            if (!empty($batchEventIds)) {
                $placeholders = implode(',', array_fill(0, count($batchEventIds), '?'));
                $this->connection->executeStatement(
                    "DELETE FROM parsed_reference WHERE source_event_id IN ({$placeholders})",
                    array_values($batchEventIds),
                );
            }

            // Bulk insert all references for this batch
            if (!empty($batchRefs)) {
                $this->bulkInsertReferences($batchRefs);
            }

            $this->dispatchSignals();
        }

        return $repaired;
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
            $this->logger->error('Graph audit: bulk insert failed, falling back to individual inserts', [
                'count' => count($refs),
                'error' => $e->getMessage(),
            ]);

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
                        ],
                    );
                } catch (\Throwable $inner) {
                    $this->logger->warning('Graph audit: failed to insert single reference', [
                        'source' => $ref->sourceEventId,
                        'target' => $ref->targetCoord,
                        'error' => $inner->getMessage(),
                    ]);
                }
            }
        }
    }
}

