<?php

namespace App\Repository;

use App\Entity\Highlight;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class HighlightRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Highlight::class);
    }

    /**
     * Extract the "pubkey:slug" suffix from a coordinate like "30023:pubkey:slug".
     * Returns null if the coordinate is not in the expected format.
     */
    private function extractCoordinateSuffix(string $coordinate): ?string
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) === 3 && $parts[1] !== '' && $parts[2] !== '') {
            return $parts[1] . ':' . $parts[2];
        }
        return null;
    }

    /**
     * Find highlights for a specific article coordinate.
     * Uses suffix matching (pubkey:slug) to be resilient against kind prefix
     * differences (e.g. 30023 vs 30024 for the same article).
     */
    public function findByArticleCoordinate(string $articleCoordinate): array
    {
        $suffix = $this->extractCoordinateSuffix($articleCoordinate);

        $qb = $this->createQueryBuilder('h')
            ->orderBy('h.createdAt', 'DESC');

        if ($suffix) {
            $qb->where('h.articleCoordinate LIKE :suffix')
                ->setParameter('suffix', '%:' . $suffix);
        } else {
            $qb->where('h.articleCoordinate = :coordinate')
                ->setParameter('coordinate', $articleCoordinate);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find highlights for an article, deduplicated by content+pubkey at DB level.
     * Keeps the most recent highlight per unique content+pubkey pair.
     * Uses suffix matching (pubkey:slug) to be resilient against kind prefix
     * differences (e.g. 30023 vs 30024 for the same article).
     */
    public function findByArticleCoordinateDeduplicated(string $articleCoordinate): array
    {
        $suffix = $this->extractCoordinateSuffix($articleCoordinate);

        // Pick the MAX(id) per content+pubkey group via raw SQL,
        // then fetch only those rows as Doctrine entities.
        // This avoids loading duplicates into PHP.
        $conn = $this->getEntityManager()->getConnection();

        if ($suffix) {
            $sql = <<<SQL
                SELECT MAX(h.id) AS id
                FROM highlight h
                WHERE h.article_coordinate LIKE :suffix
                GROUP BY MD5(CONCAT(h.content, '|', h.pubkey))
            SQL;
            $ids = $conn->fetchFirstColumn($sql, ['suffix' => '%:' . $suffix]);
        } else {
            $sql = <<<SQL
                SELECT MAX(h.id) AS id
                FROM highlight h
                WHERE h.article_coordinate = :coordinate
                GROUP BY MD5(CONCAT(h.content, '|', h.pubkey))
            SQL;
            $ids = $conn->fetchFirstColumn($sql, ['coordinate' => $articleCoordinate]);
        }

        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('h')
            ->where('h.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('h.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get cache status in a single query: whether a full refresh or top-off is needed.
     * Replaces separate needsRefresh() + getLastCacheTime() calls.
     *
     * @return array{needsFullRefresh: bool, needsTopOff: bool, lastCacheTime: ?\DateTimeImmutable}
     */
    public function getCacheStatus(string $articleCoordinate, int $fullRefreshHours = 24, int $topOffHours = 1): array
    {
        $suffix = $this->extractCoordinateSuffix($articleCoordinate);

        $qb = $this->createQueryBuilder('h')
            ->select('MAX(h.cachedAt) AS lastCached');

        if ($suffix) {
            $qb->where('h.articleCoordinate LIKE :suffix')
                ->setParameter('suffix', '%:' . $suffix);
        } else {
            $qb->where('h.articleCoordinate = :coordinate')
                ->setParameter('coordinate', $articleCoordinate);
        }

        $result = $qb->getQuery()->getOneOrNullResult();
        $lastCachedStr = $result['lastCached'] ?? null;

        if ($lastCachedStr === null) {
            return [
                'needsFullRefresh' => true,
                'needsTopOff' => false,
                'lastCacheTime' => null,
            ];
        }

        $lastCached = $lastCachedStr instanceof \DateTimeImmutable
            ? $lastCachedStr
            : new \DateTimeImmutable($lastCachedStr);
        $now = new \DateTimeImmutable();
        $diffSeconds = $now->getTimestamp() - $lastCached->getTimestamp();

        return [
            'needsFullRefresh' => $diffSeconds >= ($fullRefreshHours * 3600),
            'needsTopOff' => $diffSeconds >= ($topOffHours * 3600),
            'lastCacheTime' => $lastCached,
        ];
    }

    /**
     * Debug: Get all unique article coordinates in the database
     */
    public function getAllArticleCoordinates(): array
    {
        $results = $this->createQueryBuilder('h')
            ->select('DISTINCT h.articleCoordinate')
            ->getQuery()
            ->getResult();

        return array_map(fn($r) => $r['articleCoordinate'], $results);
    }

    /**
     * Check if we need to refresh highlights for an article
     * Returns true if cache is older than the specified hours
     */
    public function needsRefresh(string $articleCoordinate, int $hoursOld = 24): bool
    {
        $cutoff = new \DateTimeImmutable("-{$hoursOld} hours");
        $suffix = $this->extractCoordinateSuffix($articleCoordinate);

        $qb = $this->createQueryBuilder('h')
            ->select('COUNT(h.id)');

        if ($suffix) {
            $qb->where('h.articleCoordinate LIKE :suffix')
                ->setParameter('suffix', '%:' . $suffix);
        } else {
            $qb->where('h.articleCoordinate = :coordinate')
                ->setParameter('coordinate', $articleCoordinate);
        }

        $qb->andWhere('h.cachedAt > :cutoff')
            ->setParameter('cutoff', $cutoff);

        $count = $qb->getQuery()->getSingleScalarResult();

        return $count === 0;
    }

    /**
     * Get the most recent cache time for an article
     */
    public function getLastCacheTime(string $articleCoordinate): ?\DateTimeImmutable
    {
        $suffix = $this->extractCoordinateSuffix($articleCoordinate);

        $qb = $this->createQueryBuilder('h')
            ->select('h.cachedAt')
            ->orderBy('h.cachedAt', 'DESC')
            ->setMaxResults(1);

        if ($suffix) {
            $qb->where('h.articleCoordinate LIKE :suffix')
                ->setParameter('suffix', '%:' . $suffix);
        } else {
            $qb->where('h.articleCoordinate = :coordinate')
                ->setParameter('coordinate', $articleCoordinate);
        }

        $result = $qb->getQuery()->getOneOrNullResult();

        return $result ? $result['cachedAt'] : null;
    }

    /**
     * Delete old highlights for an article (before refreshing)
     */
    public function deleteOldHighlights(string $articleCoordinate, \DateTimeImmutable $before): int
    {
        $suffix = $this->extractCoordinateSuffix($articleCoordinate);

        $qb = $this->createQueryBuilder('h')
            ->delete();

        if ($suffix) {
            $qb->where('h.articleCoordinate LIKE :suffix')
                ->setParameter('suffix', '%:' . $suffix);
        } else {
            $qb->where('h.articleCoordinate = :coordinate')
                ->setParameter('coordinate', $articleCoordinate);
        }

        $qb->andWhere('h.cachedAt < :before')
            ->setParameter('before', $before);

        return $qb->getQuery()->execute();
    }

    /**
     * Get latest highlights across all articles
     * @param int $limit Maximum number of highlights to return
     * @return array<Highlight>
     */
    public function findLatest(int $limit = 200): array
    {
        return $this->createQueryBuilder('h')
            ->orderBy('h.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get latest highlights with their corresponding articles
     * Uses a join or separate query to efficiently fetch both
     * @param int $limit Maximum number of highlights to return
     * @return array<array{highlight: Highlight, article: ?\App\Entity\Article}>
     */
    public function findLatestWithArticles(int $limit = 200): array
    {
        // First get the highlights
        $highlights = $this->findLatest($limit);

        // Extract article coordinates and fetch corresponding articles
        $coordinates = array_unique(array_map(
            fn(Highlight $h) => $h->getArticleCoordinate(),
            $highlights
        ));

        // Query articles by their coordinates
        $articles = [];
        if (!empty($coordinates)) {
            $em = $this->getEntityManager();
            $articleRepo = $em->getRepository(\App\Entity\Article::class);

            // Build article coordinate map (kind:pubkey:identifier format)
            foreach ($coordinates as $coordinate) {
                // Parse coordinate: 30023:pubkey:identifier
                $parts = explode(':', $coordinate, 3);
                if (count($parts) === 3) {
                    [, $pubkey, $identifier] = $parts;

                    // Find article by pubkey and slug (identifier)
                    $article = $articleRepo->findOneBy([
                        'pubkey' => $pubkey,
                        'slug' => $identifier,
                    ]);

                    if ($article) {
                        $articles[$coordinate] = $article;
                    }
                }
            }
        }

        // Combine highlights with their articles
        $result = [];
        foreach ($highlights as $highlight) {
            $result[] = [
                'highlight' => $highlight,
                'article' => $articles[$highlight->getArticleCoordinate()] ?? null,
            ];
        }

        return $result;
    }
}

