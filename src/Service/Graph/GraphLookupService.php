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
            SELECT coord, current_event_id, kind, depth, position, relation
            FROM tree
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

        $sql = <<<'SQL'
            SELECT
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
            ORDER BY pr.position
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            'parent_coord' => $parentCoord,
        ]);
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
     * @param string[] $eventIds
     * @return array<string, array> event_id → full row
     */
    public function fetchEventRows(array $eventIds): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $sql = "SELECT * FROM event WHERE id IN ({$placeholders})";
        $rows = $this->connection->fetchAllAssociative($sql, array_values($eventIds));

        $map = [];
        foreach ($rows as $row) {
            $map[$row['id']] = $row;
        }

        return $map;
    }
}

