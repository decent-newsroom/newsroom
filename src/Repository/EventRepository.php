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
     * @param string $column The JSON column to search (e.g., 'e.tags')
     * @param string $paramPlaceholder The placeholder to use in the query (e.g., ':coordinate')
     * @return string The WHERE clause condition
     */
    private function buildJsonSearchCondition(string $column, string $paramPlaceholder): string
    {
        // PostgreSQL: Check if any array element has the value at position 1
        return "EXISTS (
            SELECT 1 FROM jsonb_array_elements({$column}::jsonb) AS tag
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
     * Find media events by kinds (20, 21, 22) excluding muted pubkeys
     *
     * @param array $kinds Array of event kinds to query
     * @param array $excludedPubkeys Array of hex pubkeys to exclude
     * @param int $limit Maximum number of events to return
     * @return Event[]
     */
    public function findMediaEvents(array $kinds = [20, 21, 22], array $excludedPubkeys = [], int $limit = 100): array
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
     * @param array $kinds Array of event kinds (default: 20, 21, 22)
     * @param int $limit Maximum number of events to return
     * @return Event[]
     */
    public function findMediaEventsByPubkey(string $pubkey, array $kinds = [20, 21, 22], int $limit = 100): array
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
     * Find media events by hashtags
     *
     * @param array $hashtags Array of hashtags to search for
     * @param array $kinds Array of event kinds (default: 20, 21, 22)
     * @param array $excludedPubkeys Array of hex pubkeys to exclude
     * @param int $limit Maximum number of events to return
     * @return Event[]
     */
    public function findMediaEventsByHashtags(array $hashtags, array $kinds = [20, 21, 22], array $excludedPubkeys = [], int $limit = 500): array
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
    public function findNonNSFWMediaEvents(array $kinds = [20, 21, 22], array $excludedPubkeys = [], int $limit = 100): array
    {
        $events = $this->findMediaEvents($kinds, $excludedPubkeys, $limit * 2);

        // Filter out NSFW events in PHP (complex JSON logic is easier here than in SQL)
        $filtered = array_filter($events, function(Event $event) {
            return !$event->isNSFW();
        });

        return array_slice($filtered, 0, $limit);
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

        // Search specifically for d-tag: ["d", "identifier"]
        $sql = "SELECT * FROM event e
                WHERE e.kind = :kind
                AND e.pubkey = :pubkey
                AND EXISTS (
                    SELECT 1 FROM jsonb_array_elements(e.tags::jsonb) AS tag
                    WHERE tag->>0 = 'd' AND tag->>1 = :identifier
                )
                ORDER BY e.created_at DESC
                LIMIT 1";

        $result = $conn->executeQuery($sql, [
            'kind' => $kind,
            'pubkey' => $pubkey,
            'identifier' => $identifier,
        ])->fetchAssociative();

        return $result ? $this->mapRowToEvent($result) : null;
    }

    /**
     * Find highlight events (kind 9802) that reference articles
     *
     * @param int $limit Maximum number of highlights to return
     * @return Event[]
     */
    public function findHighlights(int $limit = 50): array
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
     * Find latest relay list event (kind:10002) for a pubkey.
     *
     * Uses composite index (pubkey, kind, created_at DESC) for efficient lookup.
     *
     * @param string $pubkeyHex Hex pubkey
     * @return Event|null Latest kind:10002 event or null if not found
     */
    public function findLatestRelayListByPubkey(string $pubkeyHex): ?Event
    {
        return $this->createQueryBuilder('e')
            ->where('e.pubkey = :pubkey')
            ->andWhere('e.kind = :kind')
            ->setParameter('pubkey', $pubkeyHex)
            ->setParameter('kind', 10002)
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
}
