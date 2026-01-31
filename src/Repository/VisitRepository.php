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
     * Only considers routes matching /p/{npub}/d/{slug} (current article route pattern).
     * Excludes draft routes (/p/{npub}/d/{slug}/draft).
     * Returns array: [ 'route' => string, 'count' => int ]
     */
    public function getMostVisitedArticlesSince(\DateTimeImmutable $since, int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.route, COUNT(v.id) as count')
            ->where('v.visitedAt >= :since')
            ->andWhere('v.route LIKE :articlePath')
            ->andWhere('v.route NOT LIKE :draftPath')
            ->setParameter('since', $since, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('articlePath', '/p/%/d/%')
            ->setParameter('draftPath', '%/draft')
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

    /**
     * Count visits for routes matching a specific npub (author profile and articles).
     * Matches /p/{npub} and /p/{npub}/...
     */
    public function countVisitsForNpubSince(string $npub, \DateTimeImmutable $since): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.visitedAt >= :since')
            ->andWhere('v.route LIKE :npubPattern')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->setParameter('npubPattern', '/p/' . $npub . '%');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Count unique sessions visiting routes for a specific npub.
     */
    public function countUniqueVisitorsForNpubSince(string $npub, \DateTimeImmutable $since): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(DISTINCT v.sessionId)')
            ->where('v.visitedAt >= :since')
            ->andWhere('v.sessionId IS NOT NULL')
            ->andWhere('v.route LIKE :npubPattern')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->setParameter('npubPattern', '/p/' . $npub . '%');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Get most visited articles for a specific npub.
     * Matches /p/{npub}/d/{slug} pattern.
     */
    public function getMostVisitedArticlesForNpub(string $npub, \DateTimeImmutable $since, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.route, COUNT(v.id) as count')
            ->where('v.visitedAt >= :since')
            ->andWhere('v.route LIKE :articlePattern')
            ->andWhere('v.route NOT LIKE :draftPath')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->setParameter('articlePattern', '/p/' . $npub . '/d/%')
            ->setParameter('draftPath', '%/draft')
            ->groupBy('v.route')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Get visits per day for a specific npub.
     */
    public function getVisitsPerDayForNpub(string $npub, int $days = 30): array
    {
        $from = (new \DateTimeImmutable())->modify("-{$days} days");
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT DATE(visited_at) as day, COUNT(id) as count
                FROM visit
                WHERE visited_at >= :from
                AND route LIKE :npubPattern
                GROUP BY day
                ORDER BY day ASC';
        $result = $conn->executeQuery(
            $sql,
            [
                'from' => $from->format('Y-m-d H:i:s'),
                'npubPattern' => '/p/' . $npub . '%'
            ]
        );
        return $result->fetchAllAssociative();
    }

    /**
     * Get profile vs article visits breakdown for an npub.
     */
    public function getVisitBreakdownForNpub(string $npub, \DateTimeImmutable $since): array
    {
        // Profile visits (exact /p/{npub} or /p/{npub}/{tab})
        $profileQb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.visitedAt >= :since')
            ->andWhere('v.route LIKE :profilePattern')
            ->andWhere('v.route NOT LIKE :articlePattern')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->setParameter('profilePattern', '/p/' . $npub . '%')
            ->setParameter('articlePattern', '/p/' . $npub . '/d/%');
        $profileVisits = (int) $profileQb->getQuery()->getSingleScalarResult();

        // Article visits
        $articleQb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.visitedAt >= :since')
            ->andWhere('v.route LIKE :articlePattern')
            ->andWhere('v.route NOT LIKE :draftPath')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->setParameter('articlePattern', '/p/' . $npub . '/d/%')
            ->setParameter('draftPath', '%/draft');
        $articleVisits = (int) $articleQb->getQuery()->getSingleScalarResult();

        return [
            'profile' => $profileVisits,
            'articles' => $articleVisits,
            'total' => $profileVisits + $articleVisits,
        ];
    }

    /**
     * Get daily unique visitors for an npub over the last N days.
     */
    public function getDailyUniqueVisitorsForNpub(string $npub, int $days = 7): array
    {
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = (new \DateTimeImmutable("today"))->modify("-{$i} days");
            $start = $day->setTime(0, 0, 0);
            $end = $day->setTime(23, 59, 59);
            $qb = $this->createQueryBuilder('v')
                ->select('COUNT(DISTINCT v.sessionId)')
                ->where('v.visitedAt BETWEEN :start AND :end')
                ->andWhere('v.sessionId IS NOT NULL')
                ->andWhere('v.route LIKE :npubPattern')
                ->setParameter('start', $start)
                ->setParameter('end', $end)
                ->setParameter('npubPattern', '/p/' . $npub . '%');
            $count = (int) $qb->getQuery()->getSingleScalarResult();
            $result[] = [
                'day' => $day->format('Y-m-d'),
                'count' => $count
            ];
        }
        return $result;
    }
}
