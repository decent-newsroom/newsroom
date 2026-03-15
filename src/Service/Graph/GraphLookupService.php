<?php

declare(strict_types=1);

namespace App\Service\Graph;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Read-side service for graph-like traversals using the relational layer.
 *
 * Uses parsed_reference + current_record tables to answer tree/dependency
 * queries without relay round-trips. This is the primary data source
 * for Unfold's ContentProvider once the graph groundwork is in place.
 */
class GraphLookupService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly CurrentVersionResolver $currentVersionResolver,
        private readonly RecordIdentityService $identityService,
    ) {}

    /**
     * Normalize a coordinate string by lowercasing the pubkey portion.
     *
     * Input:  "30040:AbCdEf1234...:my-slug"
     * Output: "30040:abcdef1234...:my-slug"
     */
    private function normalizeCoord(string $coord): string
    {
        $parts = explode(':', $coord, 3);
        if (count($parts) >= 2) {
            $parts[1] = strtolower($parts[1]);
        }
        return implode(':', $parts);
    }

    /**
     * Resolve the full descendant tree from a root coordinate.
     *
     * Returns an ordered list of descendant coordinates with their current event IDs,
     * traversing up to $maxDepth levels. Uses a recursive CTE for efficient tree walking.
     *
     * @param string $rootCoord  Root coordinate (e.g. "30040:<pubkey>:<slug>")
     * @param int    $maxDepth   Maximum traversal depth (default 5)
     * @return array<int, array{coord: string, current_event_id: string, kind: int, depth: int, position: int}>
     */
    public function resolveDescendants(string $rootCoord, int $maxDepth = 5): array
    {
        $rootCoord = $this->normalizeCoord($rootCoord);

        $sql = <<<'SQL'
            WITH RECURSIVE tree AS (
                -- Anchor: direct children of the root
                SELECT
                    cr_child.coord,
                    cr_child.current_event_id,
                    cr_child.kind,
                    pr.position,
                    pr.relation,
                    1 AS depth
                FROM current_record cr_root
                JOIN parsed_reference pr ON pr.source_event_id = cr_root.current_event_id
                JOIN current_record cr_child ON cr_child.coord = pr.target_coord
                WHERE cr_root.coord = :root_coord
                  AND pr.is_structural = TRUE

                UNION ALL

                -- Recursive: children of children
                SELECT
                    cr_next.coord,
                    cr_next.current_event_id,
                    cr_next.kind,
                    pr2.position,
                    pr2.relation,
                    t.depth + 1
                FROM tree t
                JOIN parsed_reference pr2 ON pr2.source_event_id = t.current_event_id
                JOIN current_record cr_next ON cr_next.coord = pr2.target_coord
                WHERE t.depth < :max_depth
                  AND pr2.is_structural = TRUE
            )
            SELECT * FROM (
                SELECT DISTINCT ON (coord)
                    coord, current_event_id, kind, depth, position, relation
                FROM tree
                ORDER BY coord, depth, position
            ) deduped
            ORDER BY depth, position
        SQL;

        try {
            $rows = $this->connection->fetchAllAssociative($sql, [
                'root_coord' => $rootCoord,
                'max_depth' => $maxDepth,
            ]);

            $this->logger->debug('GraphLookupService: resolved descendants', [
                'root' => $rootCoord,
                'count' => count($rows),
            ]);

            return $rows;
        } catch (\Throwable $e) {
            $this->logger->error('GraphLookupService: descendant resolution failed', [
                'root' => $rootCoord,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Resolve immediate children of a coordinate.
     *
     * @return array<int, array{coord: string, current_event_id: string, kind: int, position: int}>
     */
    public function resolveChildren(string $parentCoord): array
    {
        $parentCoord = $this->normalizeCoord($parentCoord);

        // Step 1: Does the parent coordinate exist in current_record?
        $parentRow = $this->connection->fetchAssociative(
            'SELECT coord, current_event_id, kind FROM current_record WHERE coord = :coord',
            ['coord' => $parentCoord],
        );

        if ($parentRow === false) {
            $this->logger->warning('GraphLookupService: parent coord not found in current_record', [
                'parent_coord' => $parentCoord,
            ]);
            return [];
        }

        $parentEventId = $parentRow['current_event_id'];

        // Step 2: Does the parent event have any parsed_references?
        $allRefs = $this->connection->fetchAllAssociative(
            'SELECT target_coord, relation, is_structural, position FROM parsed_reference WHERE source_event_id = :eid ORDER BY position',
            ['eid' => $parentEventId],
        );

        if (empty($allRefs)) {
            $this->logger->warning('GraphLookupService: no parsed_references for parent event', [
                'parent_coord' => $parentCoord,
                'parent_event_id' => substr($parentEventId, 0, 16) . '...',
            ]);
            return [];
        }

        $structuralRefs = array_filter($allRefs, fn(array $r) => (bool) $r['is_structural']);

        if (empty($structuralRefs)) {
            $this->logger->warning('GraphLookupService: parsed_references exist but none are structural', [
                'parent_coord' => $parentCoord,
                'total_refs' => count($allRefs),
                'relations' => array_unique(array_column($allRefs, 'relation')),
            ]);
            return [];
        }

        // Step 3: Run the main query
        $result = $this->runChildrenQuery($parentCoord);

        // Step 4: Auto-heal — if we have structural refs but no results, try the article table
        if (empty($result) && !empty($structuralRefs)) {
            $targetCoords = array_column($structuralRefs, 'target_coord');
            $healed = $this->autoHealFromArticleTable($targetCoords);

            if ($healed > 0) {
                $this->logger->info('GraphLookupService: auto-healed current_record from article table', [
                    'parent_coord' => $parentCoord,
                    'healed_count' => $healed,
                ]);
                // Retry the query now that current_record has been populated
                $result = $this->runChildrenQuery($parentCoord);
            } else {
                $this->logger->warning('GraphLookupService: structural refs exist but targets not found in article table either', [
                    'parent_coord' => $parentCoord,
                    'structural_refs_count' => count($structuralRefs),
                    'sample_targets' => array_slice($targetCoords, 0, 5),
                ]);
            }
        }

        return $result;
    }

    /**
     * Run the children resolution query.
     */
    private function runChildrenQuery(string $parentCoord): array
    {
        $sql = <<<'SQL'
            SELECT * FROM (
                SELECT DISTINCT ON (cr_child.coord)
                    cr_child.coord,
                    cr_child.current_event_id,
                    cr_child.kind,
                    pr.position,
                    pr.relation
                FROM current_record cr_parent
                JOIN parsed_reference pr ON pr.source_event_id = cr_parent.current_event_id
                JOIN current_record cr_child ON cr_child.coord = pr.target_coord
                WHERE cr_parent.coord = :parent_coord
                  AND pr.is_structural = TRUE
                ORDER BY cr_child.coord, pr.position
            ) deduped
            ORDER BY position
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            'parent_coord' => $parentCoord,
        ]);
    }

    /**
     * Auto-heal missing current_record entries by looking up coordinates in the article table.
     *
     * Articles may exist only in the `article` table (not in `event`), so the original
     * backfill-current-records command misses them. This lazily fills the gap.
     *
     * @param string[] $targetCoords Coordinates to check/heal
     * @return int Number of records healed
     */
    private function autoHealFromArticleTable(array $targetCoords): int
    {
        $healed = 0;

        foreach ($targetCoords as $coord) {
            // Check if already in current_record
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM current_record WHERE coord = :coord',
                ['coord' => $coord],
            );
            if ($exists > 0) {
                continue;
            }

            // Decompose the coordinate: "kind:pubkey:d_tag"
            $decomposed = $this->identityService->decomposeATag($coord);
            if ($decomposed === null) {
                continue;
            }

            // Only auto-heal article kinds (30023, 30024) and publication content (30041)
            if (!in_array($decomposed['kind'], [30023, 30024, 30041], true)) {
                continue;
            }

            // Look up in article table by pubkey + slug (d-tag) + kind
            $articleRow = $this->connection->fetchAssociative(
                'SELECT event_id, kind, pubkey, slug, EXTRACT(EPOCH FROM created_at)::bigint AS created_at_ts FROM article WHERE LOWER(pubkey) = :pubkey AND slug = :slug AND kind = :kind AND event_id IS NOT NULL LIMIT 1',
                [
                    'pubkey' => strtolower($decomposed['pubkey']),
                    'slug' => $decomposed['d_tag'],
                    'kind' => $decomposed['kind'],
                ],
            );

            if ($articleRow === false) {
                continue;
            }

            $becameCurrent = $this->currentVersionResolver->updateIfCurrent(
                eventId: $articleRow['event_id'],
                kind: (int) $articleRow['kind'],
                pubkey: $articleRow['pubkey'],
                dTag: $articleRow['slug'],
                createdAt: (int) $articleRow['created_at_ts'],
            );

            if ($becameCurrent) {
                $healed++;
            }
        }

        return $healed;
    }

    /**
     * Find all ancestors (parents) of a given coordinate.
     *
     * @return array<int, array{coord: string, current_event_id: string, kind: int}>
     */
    public function resolveAncestors(string $childCoord): array
    {
        $sql = <<<'SQL'
            WITH RECURSIVE ancestors AS (
                -- Anchor: direct parents
                SELECT
                    cr_parent.coord,
                    cr_parent.current_event_id,
                    cr_parent.kind,
                    1 AS depth
                FROM current_record cr_parent
                JOIN parsed_reference pr ON pr.source_event_id = cr_parent.current_event_id
                WHERE pr.target_coord = :child_coord
                  AND pr.is_structural = TRUE

                UNION ALL

                -- Recursive: grandparents and beyond
                SELECT
                    cr_gp.coord,
                    cr_gp.current_event_id,
                    cr_gp.kind,
                    a.depth + 1
                FROM ancestors a
                JOIN parsed_reference pr2 ON pr2.source_event_id = (
                    SELECT current_event_id FROM current_record WHERE coord = (
                        SELECT coord FROM current_record cr3
                        JOIN parsed_reference pr3 ON pr3.source_event_id = cr3.current_event_id
                        WHERE pr3.target_coord = a.coord AND pr3.is_structural = TRUE
                        LIMIT 1
                    )
                )
                JOIN current_record cr_gp ON cr_gp.coord = (
                    SELECT cr4.coord FROM current_record cr4
                    WHERE cr4.current_event_id = pr2.source_event_id
                )
                WHERE a.depth < 10
            )
            SELECT DISTINCT coord, current_event_id, kind, depth
            FROM ancestors
            ORDER BY depth
        SQL;

        // Simpler reverse lookup — find all records whose parsed_references point to this coord
        $simpleSql = <<<'SQL'
            SELECT DISTINCT
                cr.coord,
                cr.current_event_id,
                cr.kind
            FROM parsed_reference pr
            JOIN current_record cr ON cr.current_event_id = pr.source_event_id
            WHERE pr.target_coord = :child_coord
              AND pr.is_structural = TRUE
        SQL;

        return $this->connection->fetchAllAssociative($simpleSql, [
            'child_coord' => $this->normalizeCoord($childCoord),
        ]);
    }

    /**
     * Resolve the current event IDs for all leaf content in a magazine tree.
     *
     * This is the primary query for Unfold rendering: given a magazine coordinate,
     * return all article/chapter event IDs grouped by category.
     *
     * @return array<string, array{coord: string, event_id: string, kind: int, children: list<array{coord: string, event_id: string, kind: int, position: int}>}>
     */
    public function resolveMagazineTree(string $magazineCoord): array
    {
        $descendants = $this->resolveDescendants($magazineCoord, 3);

        // Group by depth: depth 1 = categories, depth 2 = articles/chapters
        $categories = [];
        $leafContent = [];

        foreach ($descendants as $desc) {
            if ($desc['depth'] === 1 && $desc['kind'] === 30040) {
                $categories[$desc['coord']] = [
                    'coord' => $desc['coord'],
                    'event_id' => $desc['current_event_id'],
                    'kind' => (int) $desc['kind'],
                    'position' => (int) $desc['position'],
                    'children' => [],
                ];
            }
        }

        // Second pass: attach leaf nodes to their parent categories
        foreach ($descendants as $desc) {
            if ($desc['depth'] === 2) {
                // Find which category this belongs to
                foreach ($categories as $catCoord => &$cat) {
                    $catChildren = $this->resolveChildren($catCoord);
                    foreach ($catChildren as $child) {
                        if ($child['coord'] === $desc['coord']) {
                            $cat['children'][] = [
                                'coord' => $desc['coord'],
                                'event_id' => $desc['current_event_id'],
                                'kind' => (int) $desc['kind'],
                                'position' => (int) $desc['position'],
                            ];
                            break 2;
                        }
                    }
                }
                unset($cat);
            }
        }

        // Also handle direct children (articles/chapters directly under magazine, not under a category)
        foreach ($descendants as $desc) {
            if ($desc['depth'] === 1 && $desc['kind'] !== 30040) {
                $categories['__direct__'] = $categories['__direct__'] ?? [
                    'coord' => $magazineCoord,
                    'event_id' => '',
                    'kind' => 30040,
                    'position' => -1,
                    'children' => [],
                ];
                $categories['__direct__']['children'][] = [
                    'coord' => $desc['coord'],
                    'event_id' => $desc['current_event_id'],
                    'kind' => (int) $desc['kind'],
                    'position' => (int) $desc['position'],
                ];
            }
        }

        return array_values($categories);
    }

    /**
     * Fetch full event rows for a list of event IDs.
     *
     * Checks the `event` table first, then falls back to the `article` table's
     * `raw` JSON column for any IDs not found (articles may not have event rows).
     *
     * @param string[] $eventIds
     * @return array<string, array> event_id → full row
     */
    public function fetchEventRows(array $eventIds): array
    {
        if (empty($eventIds)) {
            return [];
        }

        // Primary: event table
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $sql = "SELECT * FROM event WHERE id IN ({$placeholders})";
        $rows = $this->connection->fetchAllAssociative($sql, array_values($eventIds));

        $map = [];
        foreach ($rows as $row) {
            $map[$row['id']] = $row;
        }

        // Find missing event IDs
        $missingIds = array_diff($eventIds, array_keys($map));

        if (!empty($missingIds)) {
            // Fallback: article table (raw JSON contains full event)
            $artPlaceholders = implode(',', array_fill(0, count($missingIds), '?'));
            $artSql = "SELECT event_id, raw FROM article WHERE event_id IN ({$artPlaceholders}) AND raw IS NOT NULL";
            $artRows = $this->connection->fetchAllAssociative($artSql, array_values($missingIds));

            foreach ($artRows as $artRow) {
                $raw = $artRow['raw'];
                if (is_string($raw)) {
                    $raw = json_decode($raw, true);
                }
                if (!is_array($raw)) {
                    continue;
                }

                // Reshape raw event JSON to match the event table row format
                $map[$artRow['event_id']] = [
                    'id' => $raw['id'] ?? $artRow['event_id'],
                    'pubkey' => $raw['pubkey'] ?? '',
                    'kind' => (int) ($raw['kind'] ?? 0),
                    'content' => $raw['content'] ?? '',
                    'tags' => is_array($raw['tags'] ?? null) ? json_encode($raw['tags']) : '[]',
                    'created_at' => (int) ($raw['created_at'] ?? 0),
                    'sig' => $raw['sig'] ?? '',
                ];
            }

            if (!empty($missingIds) && count($missingIds) !== count($artRows)) {
                $stillMissing = array_diff($missingIds, array_column($artRows, 'event_id'));
                if (!empty($stillMissing)) {
                    $this->logger->debug('GraphLookupService: some event IDs not found in event or article tables', [
                        'missing_count' => count($stillMissing),
                        'sample' => array_slice(array_values($stillMissing), 0, 3),
                    ]);
                }
            }
        }

        return $map;
    }
}

