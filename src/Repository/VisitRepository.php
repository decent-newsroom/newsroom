<?php

namespace App\Repository;

use App\Entity\Visit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Visit>
 */
class VisitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Visit::class);
    }

    public function save(Visit $visit, bool $flush = true): void
    {
        $this->getEntityManager()->persist($visit);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function getVisitCountByRoute(\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.route, COUNT(v.id) as count')
            ->groupBy('v.route')
            ->orderBy('count', 'DESC');

        if ($since) {
            $qb->where('v.visitedAt >= :since')
               ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns total number of visits since the given datetime (inclusive).
     */
    public function countVisitsSince(\DateTimeImmutable $since): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.visitedAt >= :since')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns the count of unique sessions (logged-in users) since the given datetime.
     */
    public function countUniqueSessionsSince(\DateTimeImmutable $since): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(DISTINCT v.sessionId)')
            ->where('v.visitedAt >= :since')
            ->andWhere('v.sessionId IS NOT NULL')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns visits grouped by session ID with counts.
     */
    public function getVisitsBySession(\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.sessionId, COUNT(v.id) as visitCount, MIN(v.visitedAt) as firstVisit, MAX(v.visitedAt) as lastVisit')
            ->where('v.sessionId IS NOT NULL')
            ->groupBy('v.sessionId')
            ->orderBy('visitCount', 'DESC');

        if ($since) {
            $qb->andWhere('v.visitedAt >= :since')
               ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns unique visitor count (distinct session IDs).
     */
    public function countUniqueVisitors(): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(DISTINCT v.sessionId)')
            ->where('v.sessionId IS NOT NULL');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns total number of visits.
     */
    public function getTotalVisits(): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns number of unique visitors (distinct sessionId).
     */
    public function getUniqueVisitors(): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(DISTINCT v.sessionId)')
            ->where('v.sessionId IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns visits grouped by day (YYYY-MM-DD => count) using native SQL for PostgreSQL compatibility.
     */
    public function getVisitsPerDay(int $days = 30): array
    {
        $from = (new \DateTimeImmutable())->modify("-{$days} days");
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT DATE(visited_at) as day, COUNT(id) as count
                FROM visit
                WHERE visited_at >= :from
                GROUP BY day
                ORDER BY day ASC';
        $result = $conn->executeQuery(
            $sql,
            ['from' => $from->format('Y-m-d H:i:s')]
        );
        return $result->fetchAllAssociative();
    }

    /**
     * Returns the most popular routes (top N).
     */
    public function getMostPopularRoutes(int $limit = 5): array
    {
        return $this->createQueryBuilder('v')
            ->select('v.route, COUNT(v.id) as count')
            ->groupBy('v.route')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the most recent visits (with route, sessionId, visitedAt).
     */
    public function getRecentVisits(int $limit = 10): array
    {
        return $this->createQueryBuilder('v')
            ->select('v.route, v.sessionId, v.visitedAt')
            ->orderBy('v.visitedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the average number of visits per session.
     */
    public function getAverageVisitsPerSession(): float
    {
        $totalVisits = $this->getTotalVisits();
        $uniqueSessions = $this->getUniqueVisitors();
        if ($uniqueSessions === 0) {
            return 0.0;
        }
        return round($totalVisits / $uniqueSessions, 2);
    }

    /**
     * Returns the bounce rate (percentage of sessions with only one visit).
     */
    public function getBounceRate(): float
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.sessionId, COUNT(v.id) as visitCount')
            ->where('v.sessionId IS NOT NULL')
            ->groupBy('v.sessionId');
        $sessions = $qb->getQuery()->getResult();
        $singleVisitSessions = 0;
        $totalSessions = 0;
        foreach ($sessions as $session) {
            $totalSessions++;
            if ($session['visitCount'] == 1) {
                $singleVisitSessions++;
            }
        }
        if ($totalSessions === 0) {
            return 0.0;
        }
        return round(($singleVisitSessions / $totalSessions) * 100, 2);
    }

    /**
     * Returns the top N most visited articles since a given datetime.
     * Only considers routes matching /article/d/{slug}.
     * Returns array: [ 'route' => string, 'count' => int ]
     */
    public function getMostVisitedArticlesSince(\DateTimeImmutable $since, int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.route, COUNT(v.id) as count')
            ->where('v.visitedAt >= :since')
            ->andWhere('v.route LIKE :articlePath')
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('articlePath', '/article/d/%')
            ->groupBy('v.route')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit);
        return $qb->getQuery()->getResult();
    }

    /**
     * Returns the number of times the article publish API was called since a given datetime.
     */
    public function countArticlePublishSince(\DateTimeImmutable $since): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.route = :route')
            ->andWhere('v.visitedAt >= :since')
            ->setParameter('route', '/api/article/publish')
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns article publish statistics for different time periods.
     */
    public function getArticlePublishStats(): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.route = :route')
            ->setParameter('route', '/api/article/publish');

        $allTime = (int) $qb->getQuery()->getSingleScalarResult();

        return [
            'last_hour' => $this->countArticlePublishSince(new \DateTimeImmutable('-1 hour')),
            'last_24_hours' => $this->countArticlePublishSince(new \DateTimeImmutable('-24 hours')),
            'last_7_days' => $this->countArticlePublishSince(new \DateTimeImmutable('-7 days')),
            'last_30_days' => $this->countArticlePublishSince(new \DateTimeImmutable('-30 days')),
            'all_time' => $allTime,
        ];
    }
}
