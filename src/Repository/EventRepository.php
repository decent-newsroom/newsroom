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
}
