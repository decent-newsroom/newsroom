<?php

namespace App\Repository;

use App\Entity\Event;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Event::class);
    }

    /**
     * Build a JSON search condition for PostgreSQL
     * Searches for a value in the second element (index 1) of JSON array elements
     *
     * @param string $column The JSON/JSONB column to search (e.g., 'e.tags')
     * @param string $paramPlaceholder The placeholder to use in the query (e.g., ':coordinate')
     * @return string The WHERE clause condition
     */
    private function buildJsonSearchCondition(string $column, string $paramPlaceholder): string
    {
        // PostgreSQL: Check if any array element has the value at position 1
        // The tags column is native jsonb so no cast is needed.
        return "EXISTS (
            SELECT 1 FROM jsonb_array_elements({$column}) AS tag
            WHERE tag->1 = to_jsonb({$paramPlaceholder}::text)
        )";
    }

    /**
     * Map a database row to an Event entity
     */
    private function mapRowToEvent(array $row): Event
    {
        $event = new Event();
        $event->setId($row['id']);
        $event->setKind($row['kind']);
        $event->setPubkey($row['pubkey']);
        $event->setContent($row['content']);
        $event->setCreatedAt($row['created_at']);
        $event->setSig($row['sig'] ?? null);

        // Handle tags - could be JSON string or already decoded
        $tags = $row['tags'];
        if (is_string($tags)) {
            $tags = json_decode($tags, true) ?? [];
        }
        $event->setTags($tags ?? []);

        return $event;
    }

    /**
     * Given a list of event IDs, return a map of the ones that already exist
     * in the database. Used for bulk duplicate-check before insert.
     *
     * @param  string[] $ids
     * @return array<string, true>  Set keyed by event ID
     */
    public function findExistingIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $rows = $this->createQueryBuilder('e')
            ->select('e.id')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();

        $existing = [];
        foreach ($rows as $row) {
            $existing[$row['id']] = true;
        }

        return $existing;
    }

    /**
     * Get the latest created_at timestamp among events of the given kinds.
     *
     * Used by subscription workers to set a "since" filter so the relay
     * only sends events newer than what is already in the database.
     *
     * @param int[] $kinds Event kinds to check
     * @return int|null Unix timestamp of the newest event, or null if none exist
     */
    public function findLatestCreatedAtByKinds(array $kinds): ?int
    {
        if (empty($kinds)) {
            return null;
        }

        $result = $this->createQueryBuilder('e')
            ->select('MAX(e.created_at)')
            ->where('e.kind IN (:kinds)')
            ->setParameter('kinds', $kinds)
            ->getQuery()
            ->getSingleScalarResult();

        return $result !== null ? (int) $result : null;
    }

    /**
     * Find media events by kinds (20, 21, 22, 34235, 34236) excluding muted pubkeys
     *
     * @param array $kinds Array of event kinds to query
     * @param array $excludedPubkeys Array of hex pubkeys to exclude
     * @param int $limit Maximum number of events to return
     * @return Event[]
     */
    public function findMediaEvents(array $kinds = [20, 21, 22, 34235, 34236], array $excludedPubkeys = [], int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('e');

        $qb->where($qb->expr()->in('e.kind', ':kinds'))
            ->setParameter('kinds', $kinds)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults($limit);

        if (!empty($excludedPubkeys)) {
            $qb->andWhere($qb->expr()->notIn('e.pubkey', ':excludedPubkeys'))
                ->setParameter('excludedPubkeys', $excludedPubkeys);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find media events for a specific pubkey
     *
     * @param string $pubkey Hex pubkey
     * @param array $kinds Array of event kinds (default: 20, 21, 22, 34235, 34236)
     * @param int $limit Maximum number of events to return
     * @return Event[]
     */
    public function findMediaEventsByPubkey(string $pubkey, array $kinds = [20, 21, 22, 34235, 34236], int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('e');

        $qb->where($qb->expr()->in('e.kind', ':kinds'))
            ->andWhere('e.pubkey = :pubkey')
            ->setParameter('kinds', $kinds)
            ->setParameter('pubkey', $pubkey)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Find media events for multiple pubkeys (for media follows tab).
     *
     * @param string[] $pubkeys  Hex pubkeys to include
     * @param int[]    $kinds    Event kinds (default: 20, 21, 22, 34235, 34236)
     * @param int      $limit    Maximum results
     * @return Event[]
     */
    public function findMediaEventsByPubkeys(array $pubkeys, array $kinds = [20, 21, 22, 34235, 34236], int $limit = 100): array
    {
        if (empty($pubkeys)) {
            return [];
        }

        $qb = $this->createQueryBuilder('e');

        $qb->where($qb->expr()->in('e.kind', ':kinds'))
            ->andWhere($qb->expr()->in('e.pubkey', ':pubkeys'))
            ->setParameter('kinds', $kinds)
            ->setParameter('pubkeys', $pubkeys)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Find non-NSFW media events for multiple pubkeys (for media follows tab).
     *
     * @param string[] $pubkeys  Hex pubkeys to include
     * @param int[]    $kinds    Event kinds (default: 20, 21, 22, 34235, 34236)
     * @param int      $limit    Maximum results
     * @return Event[]
     */
    public function findNonNSFWMediaEventsByPubkeys(array $pubkeys, array $kinds = [20, 21, 22, 34235, 34236], int $limit = 100): array
    {
        $events = $this->findMediaEventsByPubkeys($pubkeys, $kinds, $limit * 2);

        $filtered = array_filter($events, function (Event $event) {
            return !$event->isNSFW();
        });

        return array_slice($filtered, 0, $limit);
    }

    /**
     * Find media events by hashtags
     *
     * @param array $hashtags Array of hashtags to search for
     * @param array $kinds Array of event kinds (default: 20, 21, 22, 34235, 34236)
     * @param array $excludedPubkeys Array of hex pubkeys to exclude
     * @param int $limit Maximum number of events to return
     * @return Event[]
     */
    public function findMediaEventsByHashtags(array $hashtags, array $kinds = [20, 21, 22, 34235, 34236], array $excludedPubkeys = [], int $limit = 500): array
    {
        if (empty($hashtags)) {
            return $this->findMediaEvents($kinds, $excludedPubkeys, $limit);
        }

        $conn = $this->getEntityManager()->getConnection();
        $params = [];

        // Build kinds placeholders
        $kindsPlaceholders = [];
        foreach ($kinds as $i => $kind) {
            $kindsPlaceholders[] = ':kind_' . $i;
            $params['kind_' . $i] = $kind;
        }

        // Build hashtag conditions
        $hashtagConditions = [];
        foreach ($hashtags as $i => $hashtag) {
            $paramName = ':hashtag_' . $i;
            $hashtagConditions[] = $this->buildJsonSearchCondition('e.tags', $paramName);
            $params['hashtag_' . $i] = strtolower($hashtag);
        }

        $sql = "SELECT * FROM event e
                WHERE e.kind IN (" . implode(', ', $kindsPlaceholders) . ")
                AND (" . implode(' OR ', $hashtagConditions) . ")";

        // Build excluded pubkeys condition
        if (!empty($excludedPubkeys)) {
            $excludePlaceholders = [];
            foreach ($excludedPubkeys as $i => $pubkey) {
                $excludePlaceholders[] = ':exclude_' . $i;
                $params['exclude_' . $i] = $pubkey;
            }
            $sql .= " AND e.pubkey NOT IN (" . implode(', ', $excludePlaceholders) . ")";
        }

        $sql .= " ORDER BY e.created_at DESC LIMIT :limit";
        $params['limit'] = $limit;

        $results = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn($row) => $this->mapRowToEvent($row), $results);
    }

    /**
     * Find non-NSFW media events
     *
     * @param array $kinds Array of event kinds
     * @param array $excludedPubkeys Array of hex pubkeys to exclude
     * @param int $limit Maximum number of events to return
     * @return Event[]
     */
    public function findNonNSFWMediaEvents(array $kinds = [20, 21, 22, 34235, 34236], array $excludedPubkeys = [], int $limit = 100): array
    {
        $events = $this->findMediaEvents($kinds, $excludedPubkeys, $limit * 2);

        // Filter out NSFW events in PHP (complex JSON logic is easier here than in SQL)
        $filtered = array_filter($events, function(Event $event) {
            return !$event->isNSFW();
        });

        return array_slice($filtered, 0, $limit);
    }

    /**
     * Find the latest DB-backed curation collections for pictures/videos.
     *
     * Returns the newest version per (pubkey, kind, slug) and skips malformed
     * or empty curation events that do not reference any items.
     *
     * @param int[] $kinds
     * @param string[] $excludedPubkeys
     * @return Event[]
     */
    public function findLatestCurationCollections(array $kinds = [30005, 30006], array $excludedPubkeys = [], int $limit = 50): array
    {
        $fetchLimit = max($limit * 5, 100);

        $qb = $this->createQueryBuilder('e')
            ->where('e.kind IN (:kinds)')
            ->setParameter('kinds', $kinds)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults($fetchLimit);

        if (!empty($excludedPubkeys)) {
            $qb->andWhere('e.pubkey NOT IN (:excludedPubkeys)')
                ->setParameter('excludedPubkeys', $excludedPubkeys);
        }

        $events = $qb->getQuery()->getResult();

        $collections = [];
        $seen = [];

        foreach ($events as $event) {
            if (!$event instanceof Event) {
                continue;
            }

            $slug = $event->getSlug();
            if (!$slug) {
                continue;
            }

            $hasItems = false;
            foreach ($event->getTags() as $tag) {
                if (!is_array($tag) || !isset($tag[0], $tag[1])) {
                    continue;
                }

                if ($tag[0] === 'a' || $tag[0] === 'e') {
                    $hasItems = true;
                    break;
                }
            }

            if (!$hasItems) {
                continue;
            }

            $key = $event->getPubkey() . ':' . $event->getKind() . ':' . $slug;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $collections[] = $event;

            if (count($collections) >= $limit) {
                break;
            }
        }

        return $collections;
    }

    /**
     * Find event by ID
     *
     * @param string $id Event ID
     * @return Event|null
     */
    public function findById(string $id): ?Event
    {
        return $this->find($id);
    }

    /**
     * Find multiple events by their IDs in a single query.
     *
     * @param string[] $ids Event IDs
     * @return array<string, Event> Map of id => Event
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $events = $this->createQueryBuilder('e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($events as $event) {
            $map[$event->getId()] = $event;
        }

        return $map;
    }

    /**
     * Find event by naddr parameters (kind, pubkey, identifier)
     * Used for parameterized replaceable events (kinds 30000-39999)
     *
     * @param int $kind Event kind
     * @param string $pubkey Author pubkey
     * @param string $identifier Event identifier (d tag)
     * @return Event|null
     */
    public function findByNaddr(int $kind, string $pubkey, string $identifier): ?Event
    {
        $conn = $this->getEntityManager()->getConnection();

        // Fast path: use the d_tag column index (added in Phase 0 migration)
        $sql = "SELECT * FROM event e
                WHERE e.kind = :kind
                AND e.pubkey = :pubkey
                AND e.d_tag = :identifier
                ORDER BY e.created_at DESC
                LIMIT 1";

        $result = $conn->executeQuery($sql, [
            'kind' => $kind,
            'pubkey' => $pubkey,
            'identifier' => $identifier,
        ])->fetchAssociative();

        // Fallback: scan JSONB tags for events that predate the d_tag backfill
        if ($result === false) {
            $sql = "SELECT * FROM event e
                    WHERE e.kind = :kind
                    AND e.pubkey = :pubkey
                    AND EXISTS (
                        SELECT 1 FROM jsonb_array_elements(e.tags) AS tag
                        WHERE tag->>0 = 'd' AND tag->>1 = :identifier
                    )
                    ORDER BY e.created_at DESC
                    LIMIT 1";

            $result = $conn->executeQuery($sql, [
                'kind' => $kind,
                'pubkey' => $pubkey,
                'identifier' => $identifier,
            ])->fetchAssociative();
        }

        return $result ? $this->mapRowToEvent($result) : null;
    }

    /**
     * Batch variant of {@see findByNaddr}: resolve many addressable coordinates
     * (`kind:pubkey:d`) to their current (latest) replaceable events in a
     * single query.
     *
     * Returns a map coordinate → Event. Coordinates with no matching event
     * are absent from the map.
     *
     * Used by NIP-GX traversal to prefetch all parent / root / child addresses
     * across a stage's input items in one round-trip instead of N.
     *
     * @param string[] $coordinates Each of the form "kind:pubkey:d". Duplicates allowed; deduplicated internally.
     * @return array<string, Event>
     */
    public function findByCoordinates(array $coordinates): array
    {
        if (empty($coordinates)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        // Parse and deduplicate. Invalid coordinates are skipped silently —
        // they simply won't appear in the result map.
        $parsed = [];
        foreach (array_unique($coordinates) as $coord) {
            $parts = explode(':', $coord, 3);
            if (count($parts) !== 3 || !ctype_digit($parts[0])) {
                continue;
            }
            $parsed[$coord] = [(int) $parts[0], $parts[1], $parts[2]];
        }
        if (empty($parsed)) {
            return [];
        }

        // Build an OR of (kind, pubkey, d_tag) triples. PostgreSQL's planner
        // can use the d_tag column index efficiently when each disjunct is
        // equality on the three indexed columns; for very large batches we
        // cap at MAX_CHUNK and chunk the query, to keep parameter count
        // bounded (PG's practical limit is 65535 bind params).
        $map = [];
        foreach (array_chunk($parsed, 500, preserve_keys: true) as $chunk) {
            $whereParts = [];
            $params = [];
            $i = 0;
            foreach ($chunk as $coord => [$kind, $pubkey, $d]) {
                $whereParts[] = "(e.kind = :k{$i} AND e.pubkey = :p{$i} AND e.d_tag = :d{$i})";
                $params["k{$i}"] = $kind;
                $params["p{$i}"] = $pubkey;
                $params["d{$i}"] = $d;
                $i++;
            }

            // created_at DESC so that for replaceable events we keep the latest
            // version per coordinate when we pick the first row seen per coord.
            $sql = 'SELECT * FROM event e WHERE '
                . implode(' OR ', $whereParts)
                . ' ORDER BY e.created_at DESC';

            $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();

            foreach ($rows as $row) {
                $coord = $row['kind'] . ':' . $row['pubkey'] . ':' . ($row['d_tag'] ?? '');
                if (!isset($map[$coord])) {
                    $map[$coord] = $this->mapRowToEvent($row);
                }
            }
        }

        return $map;
    }

    /**
     * Find highlight events (kind 9802) that reference articles
     *
     * @param int $limit Maximum number of highlights to return
     * @return Event[]
     */
    public function findHighlights(int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('e');

        $qb->where('e.kind = :kind')
            ->setParameter('kind', 9802)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Save or update an event
     *
     * @param Event $event
     * @param bool $flush
     * @return void
     */
    public function save(Event $event, bool $flush = false): void
    {
        $this->getEntityManager()->persist($event);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Flush changes to database
     *
     * @return void
     */
    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * Find latest metadata event (kind:0) for a pubkey.
     *
     * Uses composite index (pubkey, kind, created_at DESC) for efficient lookup.
     *
     * @param string $pubkeyHex Hex pubkey
     * @return Event|null Latest kind:0 event or null if not found
     */
    public function findLatestMetadataByPubkey(string $pubkeyHex): ?Event
    {
        return $this->createQueryBuilder('e')
            ->where('e.pubkey = :pubkey')
            ->andWhere('e.kind = :kind')
            ->setParameter('pubkey', $pubkeyHex)
            ->setParameter('kind', 0)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }


    /**
     * Find latest profile event for a pubkey (metadata or relay list).
     *
     * Generic method that can fetch any profile-related kind.
     * Uses composite index (pubkey, kind, created_at DESC) for efficient lookup.
     *
     * @param string $pubkeyHex Hex pubkey
     * @param int $kind Event kind (0 for metadata, 10002 for relay list, etc.)
     * @return Event|null Latest event of specified kind or null if not found
     */
    public function findLatestByPubkeyAndKind(string $pubkeyHex, int $kind): ?Event
    {
        return $this->createQueryBuilder('e')
            ->where('e.pubkey = :pubkey')
            ->andWhere('e.kind = :kind')
            ->setParameter('pubkey', $pubkeyHex)
            ->setParameter('kind', $kind)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all events of a given kind authored by a pubkey.
     *
     * For parameterized replaceable events (kinds 30000–39999), only the latest
     * event per d_tag is returned (deduplication by d_tag, newest wins).
     *
     * @param string $pubkeyHex Hex pubkey
     * @param int    $kind      Event kind
     * @param int    $limit     Maximum number of results
     * @return Event[]
     */
    public function findAllByPubkeyAndKind(string $pubkeyHex, int $kind, int $limit = 100): array
    {
        $events = $this->createQueryBuilder('e')
            ->where('e.pubkey = :pubkey')
            ->andWhere('e.kind = :kind')
            ->setParameter('pubkey', $pubkeyHex)
            ->setParameter('kind', $kind)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // For parameterized replaceable events, deduplicate by d_tag (latest wins)
        if ($kind >= 30000 && $kind <= 39999) {
            $seen = [];
            $unique = [];
            foreach ($events as $event) {
                $dTag = $event->getDTag() ?? '';
                if (!isset($seen[$dTag])) {
                    $seen[$dTag] = true;
                    $unique[] = $event;
                }
            }
            return $unique;
        }

        return $events;
    }

    /**
     * Find all profile events for a pubkey (metadata and relay list).
     *
     * Returns both kind:0 and kind:10002 events for comprehensive profile data.
     * Uses composite index (pubkey, kind, created_at DESC) for efficient lookup.
     *
     * @param string $pubkeyHex Hex pubkey
     * @return Event[] Array of profile events (kind:0 and kind:10002)
     */
    public function findProfileEventsByPubkey(string $pubkeyHex): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.pubkey = :pubkey')
            ->andWhere($this->createQueryBuilder('e')->expr()->in('e.kind', ':kinds'))
            ->setParameter('pubkey', $pubkeyHex)
            ->setParameter('kinds', [0, 10002])
            ->orderBy('e.kind', 'ASC')
            ->addOrderBy('e.created_at', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find comments and zaps for an article by coordinate.
     *
     * Searches for events with kind 1111 (comments) or 9735 (zap receipts)
     * that have an "A" tag matching the article coordinate.
     *
     * @param string $coordinate Article coordinate (kind:pubkey:identifier)
     * @param int|null $since Optional timestamp to fetch only newer comments
     * @param int $limit Maximum number of comments to return
     * @return Event[] Array of comment and zap events, ordered by created_at DESC
     */
    public function findCommentsByCoordinate(string $coordinate, ?int $since = null, int $limit = 500): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "SELECT * FROM event e
                WHERE e.kind IN (1111, 9735)
                AND " . $this->buildJsonSearchCondition('e.tags', ':coordinate');

        $params = ['coordinate' => $coordinate];

        if ($since !== null && $since > 0) {
            $sql .= " AND e.created_at > :since";
            $params['since'] = $since;
        }

        $sql .= " ORDER BY e.created_at DESC LIMIT :limit";
        $params['limit'] = $limit;

        $results = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(fn($row) => $this->mapRowToEvent($row), $results);
    }

    /**
     * Get the latest comment timestamp for an article coordinate.
     * Used for incremental fetching with "since" parameter.
     *
     * @param string $coordinate Article coordinate (kind:pubkey:identifier)
     * @return int|null Latest comment timestamp or null if no comments exist
     */
    public function findLatestCommentTimestamp(string $coordinate): ?int
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "SELECT e.created_at FROM event e
                WHERE e.kind IN (1111, 9735)
                AND " . $this->buildJsonSearchCondition('e.tags', ':coordinate') . "
                ORDER BY e.created_at DESC
                LIMIT 1";

        $result = $conn->executeQuery($sql, ['coordinate' => $coordinate])->fetchAssociative();

        return $result ? (int)$result['created_at'] : null;
    }

    /**
     * Count comments for an article coordinate (excluding zap receipts).
     *
     * @param string $coordinate Article coordinate (kind:pubkey:identifier)
     * @return int Number of comments
     */
    public function countCommentsByCoordinate(string $coordinate): int
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "SELECT COUNT(e.id) FROM event e
                WHERE e.kind = 1111
                AND " . $this->buildJsonSearchCondition('e.tags', ':coordinate');

        return (int)$conn->executeQuery($sql, ['coordinate' => $coordinate])->fetchOne();
    }

    /**
     * Query events using a NIP-A7 spell filter structure.
     *
     * @param array $filter ['kinds' => int[], 'authors' => string[], '#t' => string[], 'since' => int, 'until' => int, 'limit' => int]
     * @return Event[]
     */
    public function findByFilter(array $filter): array
    {
        $qb = $this->createQueryBuilder('e');

        if (isset($filter['kinds'])) {
            $qb->andWhere('e.kind IN (:kinds)')
               ->setParameter('kinds', $filter['kinds']);
        }
        if (isset($filter['authors'])) {
            $qb->andWhere('e.pubkey IN (:authors)')
               ->setParameter('authors', $filter['authors']);
        }
        if (isset($filter['ids'])) {
            $qb->andWhere('e.id IN (:ids)')
               ->setParameter('ids', $filter['ids']);
        }
        if (isset($filter['since'])) {
            $qb->andWhere('e.created_at >= :since')
               ->setParameter('since', $filter['since']);
        }
        if (isset($filter['until'])) {
            $qb->andWhere('e.created_at <= :until')
               ->setParameter('until', $filter['until']);
        }

        // Tag filters (#t, #p, #e, etc.)
        $tagIdx = 0;
        foreach ($filter as $key => $values) {
            if (str_starts_with($key, '#') && is_array($values)) {
                $tagName = substr($key, 1);
                foreach ($values as $val) {
                    $paramTag = "tagName_{$tagIdx}";
                    $paramVal = "tagVal_{$tagIdx}";
                    $qb->andWhere("EXISTS (SELECT 1 FROM jsonb_array_elements(e.tags) AS tag WHERE tag->>0 = :{$paramTag} AND tag->>1 = :{$paramVal})")
                       ->setParameter($paramTag, $tagName)
                       ->setParameter($paramVal, $val);
                    $tagIdx++;
                }
            }
        }

        $qb->orderBy('e.created_at', 'DESC');

        if (isset($filter['limit'])) {
            $qb->setMaxResults((int) $filter['limit']);
        } else {
            $qb->setMaxResults(500);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count events of specified kinds that reference a given event.
     * Used by the CountNormalizer for engagement scoring.
     *
     * Checks both e-tag (by event ID) and a-tag (by coordinate for replaceable events).
     */
    public function countReferencingEvents(string $eventId, ?string $coordinate, array $kinds): int
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "SELECT COUNT(e.id) FROM event e WHERE e.kind IN (";
        $params = [];
        $kindPlaceholders = [];
        foreach ($kinds as $i => $kind) {
            $kindPlaceholders[] = ':kind_' . $i;
            $params['kind_' . $i] = $kind;
        }
        $sql .= implode(', ', $kindPlaceholders) . ')';

        $refConditions = "EXISTS (SELECT 1 FROM jsonb_array_elements(e.tags) AS tag WHERE tag->>0 = 'e' AND tag->>1 = :eventId)";
        $params['eventId'] = $eventId;

        if ($coordinate !== null) {
            $refConditions .= " OR EXISTS (SELECT 1 FROM jsonb_array_elements(e.tags) AS tag WHERE tag->>0 = 'a' AND tag->>1 = :coordinate)";
            $params['coordinate'] = $coordinate;
        }

        $sql .= " AND ({$refConditions})";

        return (int) $conn->executeQuery($sql, $params)->fetchOne();
    }

    /**
     * Find events of specified kinds that have a tag matching (name, value).
     *
     * Used by NIP-GX traversal operators to locate children of a given event
     * (e.g. kind:1 replies referencing an event id via `e`, kind:1111 comments
     * referencing an addressable coordinate via `a`, or kind:30040 indices
     * including an event via `a`).
     *
     * @param string $tagName The first element of the tag to match (e.g. "e", "a")
     * @param string $tagValue The second element of the tag to match (event id or coordinate)
     * @param int[] $kinds Event kinds to restrict the search to; empty means any kind
     * @param int $limit Maximum number of events to return
     * @return Event[]
     */
    public function findReferencingEvents(string $tagName, string $tagValue, array $kinds = [], int $limit = 200): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // Use the GIN index on event.tags (jsonb_path_ops) via the containment
        // operator `@>`. The equivalent jsonb_array_elements + tag->>0 / tag->>1
        // predicate does NOT use the GIN index and degrades to a sequential
        // scan on large event tables.
        $jsonTag = json_encode([[$tagName, $tagValue]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $sql = 'SELECT * FROM event e WHERE e.tags @> CAST(:jsonTag AS jsonb)';
        $params = ['jsonTag' => $jsonTag];

        if (!empty($kinds)) {
            $placeholders = [];
            foreach (array_values($kinds) as $i => $kind) {
                $placeholders[] = ':kind_' . $i;
                $params['kind_' . $i] = (int) $kind;
            }
            $sql .= ' AND e.kind IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY e.created_at ASC LIMIT :limit';
        $params['limit'] = max(1, $limit);

        $results = $conn->executeQuery($sql, $params)->fetchAllAssociative();
        return array_map(fn($row) => $this->mapRowToEvent($row), $results);
    }

    /**
     * Batch variant of {@see findReferencingEvents}: returns all events of the
     * given kinds that reference ANY of the supplied tag values.
     *
     * Used by the NIP-GX traversal operators to replace N per-item queries
     * with a single OR'd containment query (e.g. find all kind:1111 comments
     * whose `a` tag matches one of 500 coordinates in a single SQL call).
     *
     * Each containment predicate `tags @> '[[name,value]]'::jsonb` uses the
     * GIN(jsonb_path_ops) index independently, so the planner can combine
     * them via bitmap OR.
     *
     * @param string   $tagName   First element of the tag to match.
     * @param string[] $tagValues Second-element values to match (deduplicated by caller).
     * @param int[]    $kinds     Restrict to these kinds; empty = any.
     * @param int      $limit     Global row cap.
     * @return Event[]            Flat list; caller groups by tag value if needed.
     */
    public function findReferencingEventsBatch(string $tagName, array $tagValues, array $kinds = [], int $limit = 1000): array
    {
        if (empty($tagValues)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $orParts = [];
        $params = [];
        foreach (array_values(array_unique($tagValues)) as $i => $value) {
            $p = 'tag_' . $i;
            $orParts[] = 'e.tags @> CAST(:' . $p . ' AS jsonb)';
            $params[$p] = json_encode([[$tagName, (string) $value]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $sql = 'SELECT * FROM event e WHERE (' . implode(' OR ', $orParts) . ')';

        if (!empty($kinds)) {
            $placeholders = [];
            foreach (array_values($kinds) as $i => $kind) {
                $placeholders[] = ':kind_' . $i;
                $params['kind_' . $i] = (int) $kind;
            }
            $sql .= ' AND e.kind IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY e.created_at ASC LIMIT :limit';
        $params['limit'] = max(1, $limit);

        $results = $conn->executeQuery($sql, $params)->fetchAllAssociative();
        return array_map(fn($row) => $this->mapRowToEvent($row), $results);
    }

}
