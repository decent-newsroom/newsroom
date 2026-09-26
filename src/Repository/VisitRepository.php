<?php

namespace App\Repository;

use App\Entity\Visit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Visit>
 */
class VisitRepository extends ServiceEntityRepository
{
    public const ADMIN_SNAPSHOT_LIMIT = 1000000;

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

    /**
     * Return bounded, 24-hour visitor analytics for the admin overview.
     *
     * The newest rows are sampled by primary key before the time and tracking
     * filters or any aggregation are applied. This keeps the query bounded
     * even when visited_at is not indexed or contains delayed events.
     */
    public function getAdminSnapshot(): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $since = new \DateTimeImmutable('-24 hours');
        $params = [
            'sampleLimit' => self::ADMIN_SNAPSHOT_LIMIT,
            'since' => $since->format('Y-m-d H:i:s'),
            'apiRoot' => self::TRACKED_VISIT_API_ROOT,
            'apiPrefix' => self::TRACKED_VISIT_API_PREFIX,
        ];
        $types = [
            'sampleLimit' => ParameterType::INTEGER,
        ];

        $assetClauses = '';
        foreach (self::ASSET_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'adminSnapshotAsset' . $i;
            $assetClauses .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }

        $partialClauses = '';
        foreach (self::PARTIAL_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'adminSnapshotPartial' . $i;
            $partialClauses .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }

        $sql = "WITH sample AS MATERIALIZED (
                    SELECT visited_at, route, session_id, referer, is_bot
                    FROM visit
                    ORDER BY id DESC
                    LIMIT :sampleLimit
                ), recent AS MATERIALIZED (
                    SELECT *
                    FROM sample
                    WHERE visited_at >= :since
                ), tracked AS MATERIALIZED (
                    SELECT *
                    FROM recent
                    WHERE is_bot = false
                    AND route <> :apiRoot
                    AND route NOT LIKE :apiPrefix
                    {$assetClauses}
                    {$partialClauses}
                )
                SELECT
                    (SELECT COUNT(*) FROM tracked) AS visits,
                    (SELECT COUNT(DISTINCT session_id) FROM tracked WHERE session_id IS NOT NULL) AS unique_sessions,
                    (SELECT COUNT(*) FROM tracked WHERE referer IS NOT NULL AND referer <> '') AS referred_visits,
                    (SELECT COUNT(*) FROM recent) AS sampled_records,
                    (SELECT COUNT(*) FROM sample) AS source_records,
                    COALESCE(
                        (SELECT json_agg(json_build_object('route', route, 'count', visit_count)
                                         ORDER BY visit_count DESC, route ASC)
                         FROM (
                             SELECT route, COUNT(*) AS visit_count
                             FROM tracked
                             GROUP BY route
                             ORDER BY visit_count DESC, route ASC
                             LIMIT 5
                         ) top_routes),
                        '[]'::json
                    ) AS top_routes";

        $row = $conn->executeQuery($sql, $params, $types)->fetchAssociative();
        $topRoutes = json_decode((string) ($row['top_routes'] ?? '[]'), true);

        return [
            'visits' => (int) ($row['visits'] ?? 0),
            'unique_sessions' => (int) ($row['unique_sessions'] ?? 0),
            'referred_visits' => (int) ($row['referred_visits'] ?? 0),
            'sampled_records' => (int) ($row['sampled_records'] ?? 0),
            'top_routes' => is_array($topRoutes) ? $topRoutes : [],
            'sample_limit' => self::ADMIN_SNAPSHOT_LIMIT,
            'window_hours' => 24,
            'capped' => (int) ($row['source_records'] ?? 0) >= self::ADMIN_SNAPSHOT_LIMIT,
        ];
    }

    /**
     * Raw recorded requests for one exact path, grouped by calendar day.
     * Deliberately includes bots, API paths, assets, and every subdomain.
     *
     * @return list<array{day: string, count: int|string}>
     */
    public function getRawVisitCountsByExactRouteBetween(string $route, \DateTimeImmutable $since, \DateTimeImmutable $before): array
    {
        return $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT DATE(visited_at) AS day, COUNT(*) AS count
             FROM visit
             WHERE route = :route AND visited_at >= :since AND visited_at < :before
             GROUP BY DATE(visited_at)
             ORDER BY day ASC',
            [
                'route' => $route,
                'since' => $since->format('Y-m-d H:i:s'),
                'before' => $before->format('Y-m-d H:i:s'),
            ],
        )->fetchAllAssociative();
    }

    public function getVisitCountByRoute(?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.route, COUNT(v.id) as count')
            ->groupBy('v.route')
            ->orderBy('count', \SortDirection::Descending)
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
    public function getVisitsBySession(?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.sessionId, COUNT(v.id) as visitCount, MIN(v.visitedAt) as firstVisit, MAX(v.visitedAt) as lastVisit')
            ->where('v.sessionId IS NOT NULL')
            ->groupBy('v.sessionId')
            ->orderBy('visitCount', \SortDirection::Descending)
            ->setMaxResults(50);

        if ($since) {
            $qb->andWhere('v.visitedAt >= :since')
               ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

        $this->applyTrackedVisitFilters($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Calculate one detail metric from the newest 100,000 visit rows.
     * Sampling by primary key happens before the date and tracking filters.
     */
    public function getAdminDetailSampleMetric(string $metric, \DateTimeImmutable $since): int|float
    {
        $aggregate = match ($metric) {
            'visits' => 'SELECT COUNT(*) FROM tracked',
            'visitors' => 'SELECT COUNT(DISTINCT session_id) FROM tracked',
            'average' => 'SELECT COALESCE(ROUND(COUNT(*)::numeric / NULLIF(COUNT(DISTINCT session_id), 0), 2), 0) FROM tracked',
            'bounce' => 'SELECT COALESCE(ROUND(100.0 * COUNT(*) FILTER (WHERE visit_count = 1) / NULLIF(COUNT(*), 0), 2), 0) FROM (SELECT COUNT(*) AS visit_count FROM tracked WHERE session_id IS NOT NULL GROUP BY session_id) sessions',
            default => throw new \InvalidArgumentException('Unknown analytics metric'),
        };

        $params = [
            'sampleLimit' => 100000,
            'since' => $since->format('Y-m-d H:i:s'),
            'apiRoot' => self::TRACKED_VISIT_API_ROOT,
            'apiPrefix' => self::TRACKED_VISIT_API_PREFIX,
        ];
        $exclusions = '';
        foreach (self::ASSET_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'detail_asset' . $i;
            $exclusions .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }
        foreach (self::PARTIAL_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'detail_partial' . $i;
            $exclusions .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }

        $sql = "WITH sample AS MATERIALIZED (
                    SELECT visited_at, route, session_id, is_bot
                    FROM visit
                    ORDER BY id DESC
                    LIMIT :sampleLimit
                ), tracked AS MATERIALIZED (
                    SELECT *
                    FROM sample
                    WHERE visited_at >= :since
                      AND is_bot = false
                      AND route <> :apiRoot
                      AND route NOT LIKE :apiPrefix
                      {$exclusions}
                )
                {$aggregate}";

        $value = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, $params, ['sampleLimit' => ParameterType::INTEGER])
            ->fetchOne();

        return in_array($metric, ['average', 'bounce'], true) ? (float) $value : (int) $value;
    }

    /**
     * Show repeat sessions in a fixed sample of the newest visits.
     *
     * LIMIT is applied before grouping, so this report cannot scan every
     * session in a busy seven-day window. The result is a sample, not a
     * complete seven-day session ranking.
     */
    public function getRecentSessionsFromSample(\DateTimeImmutable $since): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $params = [
            'sampleLimit' => 5000,
            'since' => $since->format('Y-m-d H:i:s'),
            'apiRoot' => self::TRACKED_VISIT_API_ROOT,
            'apiPrefix' => self::TRACKED_VISIT_API_PREFIX,
        ];
        $types = ['sampleLimit' => ParameterType::INTEGER];
        $exclusions = '';
        foreach (self::ASSET_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'session_asset' . $i;
            $exclusions .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }
        foreach (self::PARTIAL_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'session_partial' . $i;
            $exclusions .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }

        $sql = "SELECT session_id AS \"sessionId\", COUNT(id) AS \"visitCount\",
                       MIN(visited_at) AS \"firstVisit\", MAX(visited_at) AS \"lastVisit\"
                FROM (
                    SELECT id, session_id, visited_at, route, is_bot
                    FROM visit
                    ORDER BY id DESC
                    LIMIT :sampleLimit
                ) recent
                WHERE visited_at >= :since
                  AND session_id IS NOT NULL
                  AND is_bot = false
                  AND route <> :apiRoot
                  AND route NOT LIKE :apiPrefix
                  {$exclusions}
                GROUP BY session_id
                HAVING COUNT(id) > 1
                ORDER BY \"visitCount\" DESC
                LIMIT 50";

        return $conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
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
     * Returns the most popular routes (top N) — all time, unbounded.
     */
    public function getMostPopularRoutes(int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.route, COUNT(v.id) as count')
            ->groupBy('v.route')
            ->orderBy('count', \SortDirection::Descending)
            ->setMaxResults($limit);

        $this->applyTrackedVisitFilters($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns the most popular routes (top N) within a time window.
     */
    public function getMostPopularRoutesSince(\DateTimeImmutable $since, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('v')
            ->select('v.route, COUNT(v.id) as count')
            ->where('v.visitedAt >= :since')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->groupBy('v.route')
            ->orderBy('count', \SortDirection::Descending)
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
            ->orderBy('v.visitedAt', \SortDirection::Descending)
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
            ->orderBy('count', \SortDirection::Descending)
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
            ->orderBy('count', \SortDirection::Descending)
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
     * Returns the average number of visits per session (all time, unbounded).
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
     * Returns the average number of visits per session within a time window.
     */
    public function getAverageVisitsPerSessionSince(\DateTimeImmutable $since): float
    {
        $conn = $this->getEntityManager()->getConnection();

        $assetClauses = '';
        $params = [
            'from' => $since->format('Y-m-d H:i:s'),
            'apiRoot' => self::TRACKED_VISIT_API_ROOT,
            'apiPrefix' => self::TRACKED_VISIT_API_PREFIX,
        ];
        foreach (self::ASSET_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'avg_asset' . $i;
            $assetClauses .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }
        $partialClauses = '';
        foreach (self::PARTIAL_ROUTE_PREFIXES as $i => $prefix) {
            $key = 'avg_partial' . $i;
            $partialClauses .= " AND route NOT LIKE :{$key}";
            $params[$key] = $prefix;
        }

        $sql = "SELECT COUNT(id) AS total_visits, COUNT(DISTINCT session_id) AS unique_sessions
                FROM visit
                WHERE visited_at >= :from
                AND is_bot = false
                AND route <> :apiRoot
                AND route NOT LIKE :apiPrefix
                {$assetClauses}
                {$partialClauses}";

        $row = $conn->executeQuery($sql, $params)->fetchAssociative();
        $uniqueSessions = (int) ($row['unique_sessions'] ?? 0);
        if ($uniqueSessions === 0) {
            return 0.0;
        }
        return round((int) $row['total_visits'] / $uniqueSessions, 2);
    }

    /**
     * Returns the bounce rate (percentage of sessions with only one visit) — all-time, unbounded.
     * Uses a native SQL subquery to avoid loading all sessions into PHP memory.
     */
    public function getBounceRate(): float
    {
        return $this->getBounceRateSince(null);
    }

    /**
     * Returns the bounce rate (percentage of sessions with only one visit) since a given datetime.
     * Pass null for all-time (unbounded — use with caution on large tables).
     */
    public function getBounceRateSince(?\DateTimeImmutable $since): float
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

        $sinceSql = '';
        if ($since !== null) {
            $sinceSql = ' AND visited_at >= :from';
            $params['from'] = $since->format('Y-m-d H:i:s');
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
                    {$sinceSql}
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
            ->orderBy('count', \SortDirection::Descending)
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
     * Returns article publish statistics for different time periods using a single SQL query.
     */
    public function getArticlePublishStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "SELECT
                    COUNT(*) FILTER (WHERE visited_at >= :lastHour) AS last_hour,
                    COUNT(*) FILTER (WHERE visited_at >= :last24Hours) AS last_24_hours,
                    COUNT(*) FILTER (WHERE visited_at >= :last7Days) AS last_7_days,
                    COUNT(*) FILTER (WHERE visited_at >= :last30Days) AS last_30_days,
                    COUNT(*) AS all_time
                FROM visit
                WHERE route = :route";

        $row = $conn->executeQuery($sql, [
            'route' => '/api/article/publish',
            'lastHour' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            'last24Hours' => (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s'),
            'last7Days' => (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s'),
            'last30Days' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s'),
        ])->fetchAssociative();

        return [
            'last_hour' => (int) ($row['last_hour'] ?? 0),
            'last_24_hours' => (int) ($row['last_24_hours'] ?? 0),
            'last_7_days' => (int) ($row['last_7_days'] ?? 0),
            'last_30_days' => (int) ($row['last_30_days'] ?? 0),
            'all_time' => (int) ($row['all_time'] ?? 0),
        ];
    }

    /**
     * One scan per period. Totals include drafts and bots, as before; the article
     * breakdown excludes drafts. Count distinct visitors across the whole period.
     */
    public function getAuthorStatsSummary(string $npub, int $days, ?\DateTimeImmutable $now = null): array
    {
        if (!in_array($days, [7, 30], true)) {
            throw new \InvalidArgumentException('Author summary supports 7 or 30 days.');
        }
        $now ??= new \DateTimeImmutable();
        $params = [
            'since' => $now->modify("-{$days} days")->format('Y-m-d H:i:s'),
            'npubPattern' => '/p/' . $npub . '%',
        ];
        $weeklyColumns = '';
        if ($days === 7) {
            $weeklyColumns = ',
                COUNT(*) FILTER (WHERE visited_at >= :since24Hours) AS visits_24_hours,
                COUNT(DISTINCT session_id) FILTER (WHERE visited_at >= :since24Hours) AS visitors_24_hours,
                COUNT(*) FILTER (WHERE route NOT LIKE :articlePattern) AS profile_visits,
                COUNT(*) FILTER (WHERE route LIKE :articlePattern AND route NOT LIKE :draftPath) AS article_visits';
            $params += [
                'since24Hours' => $now->modify('-24 hours')->format('Y-m-d H:i:s'),
                'articlePattern' => '/p/' . $npub . '/d/%',
                'draftPath' => '%/draft',
            ];
        }
        $row = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT COUNT(*) AS visits, COUNT(DISTINCT session_id) AS visitors' . $weeklyColumns . '
             FROM visit WHERE visited_at >= :since AND route LIKE :npubPattern',
            $params,
        )->fetchAssociative();
        $summary = [
            "visitsLast{$days}Days" => (int) $row['visits'],
            "uniqueVisitorsLast{$days}Days" => (int) $row['visitors'],
        ];
        if ($days === 7) {
            $profile = (int) $row['profile_visits'];
            $articles = (int) $row['article_visits'];
            $summary += [
                'visitsLast24Hours' => (int) $row['visits_24_hours'],
                'uniqueVisitorsLast24Hours' => (int) $row['visitors_24_hours'],
                'visitBreakdownLast7Days' => [
                    'profile' => $profile,
                    'articles' => $articles,
                    'total' => $profile + $articles,
                ],
            ];
        }
        return $summary;
    }

    /**
     * One grouped query for the displayed calendar days, filling missing days.
     *
     * @return list<array{day: string, visits: int, uniqueVisitors: int}>
     */
    public function getAuthorStatsChart(string $npub, int $days = 30, ?\DateTimeImmutable $today = null): array
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Chart days must be positive.');
        }
        $today = ($today ?? new \DateTimeImmutable())->setTime(0, 0);
        $from = $today->modify('-' . ($days - 1) . ' days');
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT DATE(visited_at) AS day, COUNT(*) AS visits, COUNT(DISTINCT session_id) AS visitors
             FROM visit
             WHERE visited_at >= :from AND visited_at < :before AND route LIKE :npubPattern
             GROUP BY DATE(visited_at) ORDER BY day ASC',
            [
                'from' => $from->format('Y-m-d H:i:s'),
                'before' => $today->modify('+1 day')->format('Y-m-d H:i:s'),
                'npubPattern' => '/p/' . $npub . '%',
            ],
        )->fetchAllAssociative();
        $byDay = array_column($rows, null, 'day');
        $chart = [];
        for ($i = 0; $i < $days; ++$i) {
            $day = $from->modify("+{$i} days")->format('Y-m-d');
            $chart[] = [
                'day' => $day,
                'visits' => (int) ($byDay[$day]['visits'] ?? 0),
                'uniqueVisitors' => (int) ($byDay[$day]['visitors'] ?? 0),
            ];
        }
        return $chart;
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
            ->orderBy('count', \SortDirection::Descending)
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
     * Returns zap invoice statistics for different time periods using a single SQL query.
     */
    public function getZapInvoiceStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "SELECT
                    COUNT(*) FILTER (WHERE visited_at >= :lastHour) AS last_hour,
                    COUNT(*) FILTER (WHERE visited_at >= :last24Hours) AS last_24_hours,
                    COUNT(*) FILTER (WHERE visited_at >= :last7Days) AS last_7_days,
                    COUNT(*) FILTER (WHERE visited_at >= :last30Days) AS last_30_days,
                    COUNT(*) AS all_time
                FROM visit
                WHERE route = :route";

        $row = $conn->executeQuery($sql, [
            'route' => '/zap/invoice-generated',
            'lastHour' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            'last24Hours' => (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s'),
            'last7Days' => (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s'),
            'last30Days' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s'),
        ])->fetchAssociative();

        return [
            'last_hour' => (int) ($row['last_hour'] ?? 0),
            'last_24_hours' => (int) ($row['last_24_hours'] ?? 0),
            'last_7_days' => (int) ($row['last_7_days'] ?? 0),
            'last_30_days' => (int) ($row['last_30_days'] ?? 0),
            'all_time' => (int) ($row['all_time'] ?? 0),
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
            ->orderBy('count', \SortDirection::Descending);

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
            ->orderBy('count', \SortDirection::Descending)
            ->setMaxResults($limit);

        if ($since) {
            $qb->andWhere('v.visitedAt >= :since')
               ->setParameter('since', $since, Types::DATETIME_IMMUTABLE);
        }

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
     * Returns bot-vs-human visit counts for the last 24 h, 7 d and 14 d using a single SQL query.
     */
    public function getBotVsHumanStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "SELECT
                    COUNT(*) FILTER (WHERE is_bot = true AND visited_at >= :last24Hours) AS bot_last_24_hours,
                    COUNT(*) FILTER (WHERE is_bot = false AND visited_at >= :last24Hours) AS human_last_24_hours,
                    COUNT(*) FILTER (WHERE is_bot = true AND visited_at >= :last7Days) AS bot_last_7_days,
                    COUNT(*) FILTER (WHERE is_bot = false AND visited_at >= :last7Days) AS human_last_7_days,
                    COUNT(*) FILTER (WHERE is_bot = true) AS bot_last_14_days,
                    COUNT(*) FILTER (WHERE is_bot = false) AS human_last_14_days
                FROM visit
                WHERE visited_at >= :last14Days";

        $row = $conn->executeQuery($sql, [
            'last24Hours' => (new \DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s'),
            'last7Days' => (new \DateTimeImmutable('-7 days'))->format('Y-m-d H:i:s'),
            'last14Days' => (new \DateTimeImmutable('-14 days'))->format('Y-m-d H:i:s'),
        ])->fetchAssociative() ?: [];

        $result = [];
        foreach (['last_24_hours', 'last_7_days', 'last_14_days'] as $period) {
            $bot = (int) ($row['bot_' . $period] ?? 0);
            $human = (int) ($row['human_' . $period] ?? 0);
            $total = $bot + $human;

            $result[$period] = [
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
