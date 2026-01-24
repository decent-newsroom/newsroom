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
        $qb = $this->createQueryBuilder('e');

        $qb->where($qb->expr()->in('e.kind', ':kinds'))
            ->setParameter('kinds', $kinds);

        // Build hashtag conditions - check if any of the hashtags appear in the tags JSON
        if (!empty($hashtags)) {
            $hashtagConditions = [];
            foreach ($hashtags as $index => $hashtag) {
                // JSON search for PostgreSQL: tags @> '[["t", "hashtag"]]'
                // For MySQL: JSON_CONTAINS or JSON_SEARCH
                $paramName = 'hashtag_' . $index;
                $hashtagConditions[] = "JSON_SEARCH(e.tags, 'one', :{$paramName}, NULL, '$[*][1]') IS NOT NULL";
                $qb->setParameter($paramName, strtolower($hashtag));
            }
            $qb->andWhere($qb->expr()->orX(...$hashtagConditions));
        }

        if (!empty($excludedPubkeys)) {
            $qb->andWhere($qb->expr()->notIn('e.pubkey', ':excludedPubkeys'))
                ->setParameter('excludedPubkeys', $excludedPubkeys);
        }

        $qb->orderBy('e.created_at', 'DESC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
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
        $qb = $this->createQueryBuilder('e');

        $qb->where('e.kind = :kind')
            ->andWhere('e.pubkey = :pubkey')
            ->setParameter('kind', $kind)
            ->setParameter('pubkey', $pubkey);

        // Search for the 'd' tag with the identifier
        // JSON search: find events where tags contain ['d', identifier]
        $qb->andWhere("JSON_SEARCH(e.tags, 'one', :identifier, NULL, '$[*][1]') IS NOT NULL")
            ->setParameter('identifier', $identifier);

        $qb->orderBy('e.created_at', 'DESC')
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
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
}
