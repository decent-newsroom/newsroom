<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Graph\CurrentVersionResolver;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fixAll = $input->getOption('fix');
        $fixVersions = $fixAll || $input->getOption('fix-versions');
        $fixReferences = $fixAll || $input->getOption('fix-references');
        $limit = (int) $input->getOption('limit');

        $io->title('Graph Layer Audit');

        // ── Check 1: current_record freshness ──────────────────────────────
        $io->section('1. Current-record freshness');
        $staleRecords = $this->auditCurrentRecordFreshness($io, $limit);

        if ($fixVersions && !empty($staleRecords)) {
            $io->info(sprintf('Repairing %d stale current_record entries...', count($staleRecords)));
            $repaired = $this->repairCurrentRecords($staleRecords);
            $io->success(sprintf('Repaired %d / %d entries.', $repaired, count($staleRecords)));
        }

        // ── Check 2: parsed_reference completeness ─────────────────────────
        $io->section('2. Parsed-reference completeness');
        $refIssues = $this->auditParsedReferences($io, $limit);

        if ($fixReferences && !empty($refIssues)) {
            $io->info(sprintf('Repairing %d events with stale/missing references...', count($refIssues)));
            $repaired = $this->repairReferences($refIssues);
            $io->success(sprintf('Repaired %d / %d events.', $repaired, count($refIssues)));
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

        return $totalIssues === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Check 1: For each current_record, verify that its current_event_id
     * matches the actual newest event for that coordinate in the event table.
     *
     * @return array<array{coord: string, stored_event_id: string, actual_event_id: string, kind: int, pubkey: string, d_tag: ?string}>
     */
    private function auditCurrentRecordFreshness(SymfonyStyle $io, int $limit): array
    {
        // Find current_record entries where the stored event is NOT the newest
        // for that (kind, pubkey, d_tag) tuple in the event table.
        $sql = <<<'SQL'
            SELECT
                cr.coord,
                cr.current_event_id AS stored_event_id,
                cr.kind,
                cr.pubkey,
                cr.d_tag,
                newest.id AS actual_event_id,
                newest.created_at AS actual_created_at,
                cr.current_created_at AS stored_created_at
            FROM current_record cr
            JOIN LATERAL (
                SELECT e.id, e.created_at
                FROM event e
                WHERE e.kind = cr.kind
                  AND e.pubkey = cr.pubkey
                  AND (
                      (cr.d_tag IS NOT NULL AND e.d_tag = cr.d_tag)
                      OR (cr.d_tag IS NULL AND e.d_tag IS NULL)
                  )
                ORDER BY e.created_at DESC, e.id ASC
                LIMIT 1
            ) newest ON TRUE
            WHERE newest.id != cr.current_event_id
        SQL;

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        try {
            $stale = $this->connection->fetchAllAssociative($sql);
        } catch (\Throwable $e) {
            $io->error('Failed to check current_record freshness: ' . $e->getMessage());
            $this->logger->error('Graph audit: current_record freshness check failed', ['error' => $e->getMessage()]);
            return [];
        }

        if (empty($stale)) {
            $io->info('✓ All current_record entries are fresh.');
        } else {
            $io->warning(sprintf('Found %d stale current_record entries.', count($stale)));
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
     * Check 2: Sample events with `a` tags and verify parsed_reference count matches.
     *
     * @return array<array{event_id: string, kind: int, expected: int, stored: int}>
     */
    private function auditParsedReferences(SymfonyStyle $io, int $limit): array
    {
        $checkLimit = $limit > 0 ? $limit : 1000;

        // Get a sample of events that have a-tags
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, kind, tags FROM event WHERE tags::text LIKE '%\"a\"%' ORDER BY created_at DESC LIMIT :lim",
            ['lim' => $checkLimit],
            ['lim' => ParameterType::INTEGER],
        );

        $issues = [];

        foreach ($rows as $row) {
            $tags = is_string($row['tags']) ? json_decode($row['tags'], true) : $row['tags'];
            if (!is_array($tags)) {
                continue;
            }

            $expected = $this->referenceParser->parseFromTagsArray($row['id'], (int) $row['kind'], $tags);
            $expectedCount = count($expected);

            $storedCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM parsed_reference WHERE source_event_id = ?',
                [$row['id']],
            );

            if ($expectedCount !== $storedCount) {
                $issues[] = [
                    'event_id' => $row['id'],
                    'kind' => (int) $row['kind'],
                    'expected' => $expectedCount,
                    'stored' => $storedCount,
                ];
            }
        }

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
     * Check 3: Find current_record entries whose current_event_id
     * doesn't exist in either event or article tables.
     */
    private function auditOrphans(SymfonyStyle $io, bool $fix): int
    {
        $sql = <<<'SQL'
            SELECT cr.coord, cr.current_event_id, cr.kind
            FROM current_record cr
            LEFT JOIN event e ON e.id = cr.current_event_id
            LEFT JOIN article a ON a.event_id = cr.current_event_id
            WHERE e.id IS NULL AND a.event_id IS NULL
        SQL;

        try {
            $orphans = $this->connection->fetchAllAssociative($sql);
        } catch (\Throwable $e) {
            $io->error('Failed to detect orphans: ' . $e->getMessage());
            return 0;
        }

        if (empty($orphans)) {
            $io->info('✓ No orphaned current_record entries.');
            return 0;
        }

        $io->warning(sprintf('Found %d orphaned current_record entries (event missing from both tables).', count($orphans)));
        $sample = array_slice($orphans, 0, 5);
        $tableRows = array_map(fn(array $r) => [
            $r['coord'],
            substr($r['current_event_id'], 0, 16) . '…',
            $r['kind'],
        ], $sample);
        $io->table(['Coord', 'Missing Event ID', 'Kind'], $tableRows);

        if ($fix) {
            $deleted = $this->connection->executeStatement(
                'DELETE FROM current_record WHERE record_uid IN (SELECT cr.record_uid FROM current_record cr LEFT JOIN event e ON e.id = cr.current_event_id LEFT JOIN article a ON a.event_id = cr.current_event_id WHERE e.id IS NULL AND a.event_id IS NULL)',
            );
            $io->info(sprintf('Removed %d orphaned current_record entries.', $deleted));
        }

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
        }

        return $repaired;
    }

    /**
     * Repair missing/stale parsed_reference rows by re-parsing from event tags.
     */
    private function repairReferences(array $issues): int
    {
        $repaired = 0;

        foreach ($issues as $issue) {
            $eventId = $issue['event_id'];

            try {
                $row = $this->connection->fetchAssociative(
                    'SELECT id, kind, tags FROM event WHERE id = ?',
                    [$eventId],
                );

                if ($row === false) {
                    continue;
                }

                $tags = is_string($row['tags']) ? json_decode($row['tags'], true) : $row['tags'];
                if (!is_array($tags)) {
                    continue;
                }

                $refs = $this->referenceParser->parseFromTagsArray($eventId, (int) $row['kind'], $tags);

                // Delete existing and reinsert
                $this->connection->executeStatement(
                    'DELETE FROM parsed_reference WHERE source_event_id = ?',
                    [$eventId],
                );

                foreach ($refs as $ref) {
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
                }

                $repaired++;
            } catch (\Throwable $e) {
                $this->logger->warning('Graph audit: failed to repair references', [
                    'event_id' => $eventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $repaired;
    }
}

