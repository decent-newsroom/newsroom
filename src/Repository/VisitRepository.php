<?php

namespace App\Repository;

use App\Entity\Visit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Visit>
 */
class VisitRepository extends ServiceEntityRepository
{
    private const TRACKED_VISIT_API_ROOT = '/api';
    private const TRACKED_VISIT_API_PREFIX = '/api/%';

    /** Asset route prefixes excluded from generic visitor analytics. */
    private const ASSET_ROUTE_PREFIXES = [
        '/assets/%',
        '/icons/%',
        '/fonts/%',
        '/themes/%',
        '/unfold-themes/%',
    ];

    /** Partial/preview route prefixes excluded from page-view analytics. */
    private const PARTIAL_ROUTE_PREFIXES = [
        '/editor/markdown/preview%',
        '/article-editor/preview/%',
        '/preview/%', // deprecated, but want to exclude old items
    ];

    private ManagerRegistry $registry;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Visit::class);
        $this->registry = $registry;
    }

    public function save(Visit $visit, bool $flush = true): void
    {
        $em = $this->getEntityManager();

        // In FrankenPHP worker mode, a previous failed flush can leave the EntityManager
        // closed. Reset it so subsequent requests in this worker aren't permanently broken.
        if (!$em->isOpen()) {
            $this->registry->resetManager();
            $em = $this->getEntityManager();
        }

        $em->persist($visit);

        if ($flush) {
            $em->flush();
        }
    }

    public function getVisitCountByRoute(\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.route, COUNT(v.id) as count')
            ->groupBy('v.route')
            ->orderBy('count', 'DESC')
            ->setMaxResults(100);

        if ($since) {
            $qb->where('v.visitedAt >= :since')
               ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        $this->applyTrackedVisitFilters($qb);

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

        $this->applyTrackedVisitFilters($qb);

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

        $this->applyTrackedVisitFilters($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns visits grouped by session ID with counts (top 50 by visit count).
     */
    public function getVisitsBySession(\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.sessionId, COUNT(v.id) as visitCount, MIN(v.visitedAt) as firstVisit, MAX(v.visitedAt) as lastVisit')
            ->where('v.sessionId IS NOT NULL')
            ->groupBy('v.sessionId')
            ->orderBy('visitCount', 'DESC')
            ->setMaxResults(50);

        if ($since) {
            $qb->andWhere('v.visitedAt >= :since')
               ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        $this->applyTrackedVisitFilters($qb);

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

        $this->applyTrackedVisitFilters($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns total number of visits.
     */
    public function getTotalVisits(): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)');

        $this->applyTrackedVisitFilters($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns number of unique visitors (distinct sessionId).
     */
    public function getUniqueVisitors(): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(DISTINCT v.sessionId)')
            ->where('v.sessionId IS NOT NULL');

        $this->applyTrackedVisitFilters($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns visits grouped by day (YYYY-MM-DD => count) using native SQL for PostgreSQL compatibility.
     */
    public function getVisitsPerDay(int $days = 30): array
    {
        $from = (new \DateTimeImmutable())->modify("-{$days} days");
        $conn = $this->getEntityManager()->getConnection();

        $assetClauses = '';
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'apiRoot' => self::TRACKED_VISIT_API_ROOT,
            'apiPrefix' => self::TRACKED_VISIT_API_PREFIX,
        ];
        foreach (self::ASSET_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'asset' . $i;
            $assetClauses .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }

        $partialClauses = '';
        foreach (self::PARTIAL_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'partial' . $i;
            $partialClauses .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }

        $sql = "SELECT DATE(visited_at) as day, COUNT(id) as count
                FROM visit
                WHERE visited_at >= :from
                AND is_bot = false
                AND route <> :apiRoot
                AND route NOT LIKE :apiPrefix
                {$assetClauses}
                {$partialClauses}
                GROUP BY day
                ORDER BY day ASC";
        $result = $conn->executeQuery($sql, $params);
        return $result->fetchAllAssociative();
    }

    /**
     * Returns the most popular routes (top N).
     */
    public function getMostPopularRoutes(int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.route, COUNT(v.id) as count')
            ->groupBy('v.route')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit);

        $this->applyTrackedVisitFilters($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns the most recent visits (with route, sessionId, visitedAt).
     */
    public function getRecentVisits(int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.route, v.sessionId, v.referer, v.userAgent, v.visitedAt')
            ->orderBy('v.visitedAt', 'DESC')
            ->setMaxResults($limit);

        $this->applyTrackedVisitFilters($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns the number of tracked visits that include a referer header.
     */
    public function countVisitsWithReferer(?\DateTimeImmutable $since = null): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)');

        if ($since) {
            $qb->where('v.visitedAt >= :since')
                ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        $this->applyTrackedVisitFilters($qb);
        $this->applyRefererPresenceFilter($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns the most common referers for tracked visits.
     */
    public function getTopReferers(int $limit = 10, ?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.referer, COUNT(v.id) as count')
            ->groupBy('v.referer')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit);

        if ($since) {
            $qb->where('v.visitedAt >= :since')
                ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        $this->applyTrackedVisitFilters($qb);
        $this->applyRefererPresenceFilter($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns the most common referers that do NOT contain the given base domain (external referers).
     */
    public function getTopExternalReferers(string $baseDomain, int $limit = 15, ?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.referer, COUNT(v.id) as count')
            ->groupBy('v.referer')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit);

        if ($since) {
            $qb->where('v.visitedAt >= :since')
                ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        $this->applyTrackedVisitFilters($qb);
        $this->applyRefererPresenceFilter($qb);
        $this->addCondition($qb, 'v.referer NOT LIKE :baseDomainPattern');
        $qb->setParameter('baseDomainPattern', '%' . $baseDomain . '%');

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns daily unique visitor counts for the last N days, excluding utility routes.
     * Uses a single SQL query instead of N separate queries.
     */
    public function getDailyUniqueVisitors(int $days = 7): array
    {
        $from = (new \DateTimeImmutable('today'))->modify("-{$days} days");
        $conn = $this->getEntityManager()->getConnection();

        $assetClauses = '';
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'apiRoot' => self::TRACKED_VISIT_API_ROOT,
            'apiPrefix' => self::TRACKED_VISIT_API_PREFIX,
        ];
        foreach (self::ASSET_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'asset' . $i;
            $assetClauses .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }
        $partialClauses = '';
        foreach (self::PARTIAL_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'partial' . $i;
            $partialClauses .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }

        $sql = "SELECT DATE(visited_at) as day, COUNT(DISTINCT session_id) as count
                FROM visit
                WHERE visited_at >= :from
                AND session_id IS NOT NULL
                AND is_bot = false
                AND route <> :apiRoot
                AND route NOT LIKE :apiPrefix
                {$assetClauses}
                {$partialClauses}
                GROUP BY day
                ORDER BY day ASC";

        return $conn->executeQuery($sql, $params)->fetchAllAssociative();
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
     * Uses a native SQL subquery to avoid loading all sessions into PHP memory.
     */
    public function getBounceRate(): float
    {
        $conn = $this->getEntityManager()->getConnection();

        $assetClauses = '';
        $params = [
            'apiRoot' => self::TRACKED_VISIT_API_ROOT,
            'apiPrefix' => self::TRACKED_VISIT_API_PREFIX,
        ];
        foreach (self::ASSET_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'br_asset' . $i;
            $assetClauses .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }
        $partialClauses = '';
        foreach (self::PARTIAL_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'br_partial' . $i;
            $partialClauses .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }

        $sql = "SELECT
                    COUNT(*) FILTER (WHERE cnt = 1) AS single_visits,
                    COUNT(*) AS total_sessions
                FROM (
                    SELECT COUNT(id) AS cnt
                    FROM visit
                    WHERE session_id IS NOT NULL
                    AND is_bot = false
                    AND route <> :apiRoot
                    AND route NOT LIKE :apiPrefix
                    {$assetClauses}
                    {$partialClauses}
                    GROUP BY session_id
                ) sub";

        $row = $conn->executeQuery($sql, $params)->fetchAssociative();

        $totalSessions = (int) ($row['total_sessions'] ?? 0);
        if ($totalSessions === 0) {
            return 0.0;
        }

        return round(((int) $row['single_visits'] / $totalSessions) * 100, 2);
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

    /**
     * Returns the number of times a zap invoice was generated since a given datetime.
     */
    public function countZapInvoicesSince(\DateTimeImmutable $since): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.route = :route')
            ->andWhere('v.visitedAt >= :since')
            ->setParameter('route', '/zap/invoice-generated')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns zap invoice statistics for different time periods.
     */
    public function getZapInvoiceStats(): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.route = :route')
            ->setParameter('route', '/zap/invoice-generated');

        $allTime = (int) $qb->getQuery()->getSingleScalarResult();

        return [
            'last_hour' => $this->countZapInvoicesSince(new \DateTimeImmutable('-1 hour')),
            'last_24_hours' => $this->countZapInvoicesSince(new \DateTimeImmutable('-24 hours')),
            'last_7_days' => $this->countZapInvoicesSince(new \DateTimeImmutable('-7 days')),
            'last_30_days' => $this->countZapInvoicesSince(new \DateTimeImmutable('-30 days')),
            'all_time' => $allTime,
        ];
    }

    // ── Subdomain analytics ─────────────────────────────────────────────

    /**
     * Returns visit counts grouped by subdomain for tracked visits.
     * Only includes rows where subdomain IS NOT NULL.
     */
    public function getSubdomainVisitCounts(?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.subdomain, COUNT(v.id) as count')
            ->andWhere('v.subdomain IS NOT NULL')
            ->groupBy('v.subdomain')
            ->orderBy('count', 'DESC');

        if ($since) {
            $qb->andWhere('v.visitedAt >= :since')
               ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        $this->applyTrackedVisitFilters($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns total subdomain visit count since a given datetime.
     */
    public function countSubdomainVisitsSince(\DateTimeImmutable $since): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.visitedAt >= :since')
            ->andWhere('v.subdomain IS NOT NULL')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);

        $this->applyTrackedVisitFilters($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns total subdomain visits (all time).
     */
    public function countTotalSubdomainVisits(): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.subdomain IS NOT NULL');

        $this->applyTrackedVisitFilters($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns unique visitors (sessions) for subdomain traffic since a given datetime.
     */
    public function countUniqueSubdomainVisitorsSince(\DateTimeImmutable $since): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(DISTINCT v.sessionId)')
            ->where('v.visitedAt >= :since')
            ->andWhere('v.subdomain IS NOT NULL')
            ->andWhere('v.sessionId IS NOT NULL')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);

        $this->applyTrackedVisitFilters($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns visits per day for subdomain traffic over the last N days.
     */
    public function getSubdomainVisitsPerDay(int $days = 30): array
    {
        $from = (new \DateTimeImmutable())->modify("-{$days} days");
        $conn = $this->getEntityManager()->getConnection();

        $assetClauses = '';
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'apiRoot' => self::TRACKED_VISIT_API_ROOT,
            'apiPrefix' => self::TRACKED_VISIT_API_PREFIX,
        ];
        foreach (self::ASSET_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'asset' . $i;
            $assetClauses .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }

        $partialClauses = '';
        foreach (self::PARTIAL_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'partial' . $i;
            $partialClauses .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }

        $sql = "SELECT DATE(visited_at) as day, COUNT(id) as count
                FROM visit
                WHERE visited_at >= :from
                AND subdomain IS NOT NULL
                AND is_bot = false
                AND route <> :apiRoot
                AND route NOT LIKE :apiPrefix
                {$assetClauses}
                {$partialClauses}
                GROUP BY day
                ORDER BY day ASC";
        $result = $conn->executeQuery($sql, $params);
        return $result->fetchAllAssociative();
    }

    /**
     * Returns the top routes visited on subdomains.
     */
    public function getTopSubdomainRoutes(int $limit = 10, ?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.subdomain, v.route, COUNT(v.id) as count')
            ->andWhere('v.subdomain IS NOT NULL')
            ->groupBy('v.subdomain, v.route')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit);

        if ($since) {
            $qb->andWhere('v.visitedAt >= :since')
               ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        $this->applyTrackedVisitFilters($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns recent visits on subdomains.
     */
    public function getRecentSubdomainVisits(int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.subdomain, v.route, v.sessionId, v.referer, v.visitedAt')
            ->andWhere('v.subdomain IS NOT NULL')
            ->orderBy('v.visitedAt', 'DESC')
            ->setMaxResults($limit);

        $this->applyTrackedVisitFilters($qb);

        return $qb->getQuery()->getResult();
    }

    private function applyTrackedVisitFilters(QueryBuilder $qb, string $alias = 'v'): void
    {
        $this->addCondition($qb, sprintf('%s.route <> :trackedVisitApiRoot', $alias));
        $this->addCondition($qb, sprintf('%s.route NOT LIKE :trackedVisitApiPrefix', $alias));
        // Exclude bot traffic from all analytics by default
        $this->addCondition($qb, sprintf('%s.isBot = :notBot', $alias));

        $qb->setParameter('trackedVisitApiRoot', self::TRACKED_VISIT_API_ROOT);
        $qb->setParameter('trackedVisitApiPrefix', self::TRACKED_VISIT_API_PREFIX);
        $qb->setParameter('notBot', false);

        // Exclude asset routes
        foreach (self::ASSET_ROUTE_PREFIXES as $i => $prefix) {
            $param = 'trackedVisitAssetPrefix' . $i;
            $this->addCondition($qb, sprintf('%s.route NOT LIKE :%s', $alias, $param));
            $qb->setParameter($param, $prefix);
        }

        // Exclude partial/preview routes
        foreach (self::PARTIAL_ROUTE_PREFIXES as $i => $prefix) {
            $param = 'trackedVisitPartialPrefix' . $i;
            $this->addCondition($qb, sprintf('%s.route NOT LIKE :%s', $alias, $param));
            $qb->setParameter($param, $prefix);
        }
    }

    // ── Bot traffic analytics ─────────────────────────────────────────────

    /**
     * Returns the total number of bot-flagged visits since the given datetime (or all time).
     */
    public function countBotVisitsSince(?\DateTimeImmutable $since = null): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.isBot = :isBot')
            ->setParameter('isBot', true);

        if ($since) {
            $qb->andWhere('v.visitedAt >= :since')
               ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns the top User-Agent strings from bot visits (last N days).
     */
    public function getTopBotUserAgents(int $limit = 20, ?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.userAgent, COUNT(v.id) as count')
            ->where('v.isBot = :isBot')
            ->andWhere('v.userAgent IS NOT NULL')
            ->setParameter('isBot', true)
            ->groupBy('v.userAgent')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit);

        if ($since) {
            $qb->andWhere('v.visitedAt >= :since')
               ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns bot visits per day for the last N days.
     */
    public function getBotVisitsPerDay(int $days = 30): array
    {
        $from = (new \DateTimeImmutable())->modify("-{$days} days");
        $conn = $this->getEntityManager()->getConnection();

        $sql = "SELECT DATE(visited_at) as day, COUNT(id) as count
                FROM visit
                WHERE is_bot = true
                AND visited_at >= :from
                GROUP BY day
                ORDER BY day ASC";

        return $conn->executeQuery($sql, ['from' => $from->format('Y-m-d H:i:s')])->fetchAllAssociative();
    }

    /**
     * Returns bot-vs-human visit counts for the last 24 h, 7 d and 30 d.
     */
    public function getBotVsHumanStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $periods = [
            'last_24_hours' => '-24 hours',
            'last_7_days'   => '-7 days',
            'last_30_days'  => '-30 days',
        ];

        $result = [];
        foreach ($periods as $key => $modifier) {
            $from = (new \DateTimeImmutable($modifier))->format('Y-m-d H:i:s');
            $row  = $conn->executeQuery(
                "SELECT
                    COUNT(*) FILTER (WHERE is_bot = true)  AS bot,
                    COUNT(*) FILTER (WHERE is_bot = false) AS human
                 FROM visit WHERE visited_at >= :from",
                ['from' => $from]
            )->fetchAssociative();

            $bot   = (int) ($row['bot']   ?? 0);
            $human = (int) ($row['human'] ?? 0);
            $total = $bot + $human;

            $result[$key] = [
                'bot'        => $bot,
                'human'      => $human,
                'total'      => $total,
                'bot_pct'    => $total > 0 ? round($bot / $total * 100, 1) : 0.0,
            ];
        }

        return $result;
    }

    private function applyRefererPresenceFilter(QueryBuilder $qb, string $alias = 'v'): void
    {
        $this->addCondition($qb, sprintf('%s.referer IS NOT NULL', $alias));
        $this->addCondition($qb, sprintf('%s.referer <> :trackedVisitEmptyReferer', $alias));

        $qb->setParameter('trackedVisitEmptyReferer', '');
    }

    private function addCondition(QueryBuilder $qb, string $condition): void
    {
        if (null === $qb->getDQLPart('where')) {
            $qb->where($condition);

            return;
        }

        $qb->andWhere($condition);
    }
}
