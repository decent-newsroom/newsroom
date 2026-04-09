<?php

namespace App\Repository;

use App\Dto\SearchFilters;
use App\Entity\Article;
use App\Enum\KindsEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /**
     * Find latest articles, grouped by slug (one per slug), excluding muted pubkeys
     *
     * @param int $limit
     * @param array<string> $excludedPubkeys
     * @return Article[]
     */
    public function findLatestArticles(int $limit = 50, array $excludedPubkeys = []): array
    {
        $qb = $this->createQueryBuilder('a');

        $qb->where('a.publishedAt IS NOT NULL')
            ->andWhere('a.slug IS NOT NULL')
            ->andWhere('a.title IS NOT NULL')
            ->andWhere('a.kind != :draftKind')
            ->setParameter('draftKind', KindsEnum::LONGFORM_DRAFT)
            ->andWhere($qb->expr()->notLike('a.slug', ':slugPattern'))
            ->setParameter('slugPattern', '%/%')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit * 2); // Get more initially, will dedupe by slug

        if (!empty($excludedPubkeys)) {
            $qb->andWhere($qb->expr()->notIn('a.pubkey', ':excludedPubkeys'))
                ->setParameter('excludedPubkeys', $excludedPubkeys);
        }

        /** @var Article[] $allArticles */
        $allArticles = $qb->getQuery()->getResult();

        // Group by slug and keep the most recent version of each
        $slugMap = [];
        foreach ($allArticles as $article) {
            $slug = $article->getSlug();
            if (!isset($slugMap[$slug]) || $article->getCreatedAt() > $slugMap[$slug]->getCreatedAt()) {
                $slugMap[$slug] = $article;
            }
        }

        // Sort by createdAt DESC and limit
        usort($slugMap, function($a, $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        return array_slice(array_values($slugMap), 0, $limit);
    }

    /**
     * Search articles by query string using database full-text search
     *
     * @param string $query
     * @param int $limit
     * @param int $offset
     * @return Article[]
     */
    public function searchByQuery(string $query, int $limit = 12, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('a');

        // Use LIKE for basic text search (works with PostgreSQL and MySQL)
        // For better performance, consider using PostgreSQL full-text search or MySQL FULLTEXT indexes
        $searchTerm = '%' . $query . '%';

        $qb->where(
            $qb->expr()->orX(
                $qb->expr()->like('a.title', ':search'),
                $qb->expr()->like('a.content', ':search'),
                $qb->expr()->like('a.summary', ':search')
            )
        )
        ->andWhere($qb->expr()->notLike('a.slug', ':slugPattern'))
        ->setParameter('search', $searchTerm)
        ->setParameter('slugPattern', '%/%')
        ->orderBy('a.createdAt', 'DESC')
        ->setFirstResult($offset)
        ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Find articles by slugs
     *
     * @param array $slugs
     * @param int $limit
     * @return Article[]
     */
    public function findBySlugs(array $slugs, int $limit = 200): array
    {
        if (empty($slugs)) {
            return [];
        }

        $qb = $this->createQueryBuilder('a');

        $qb->where($qb->expr()->in('a.slug', ':slugs'))
            ->setParameter('slugs', $slugs)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit);

        $results = $qb->getQuery()->getResult();

        // Group by slug and keep the most recent version of each
        $slugMap = [];
        foreach ($results as $article) {
            $slug = $article->getSlug();
            if (!isset($slugMap[$slug]) || $article->getCreatedAt() > $slugMap[$slug]->getCreatedAt()) {
                $slugMap[$slug] = $article;
            }
        }

        return array_values($slugMap);
    }

    /**
     * Find articles by topics
     *
     * @param array $topics
     * @param int $limit
     * @param int $offset
     * @return Article[]
     * @throws Exception|\JsonException
     */
    public function findByTopics(array $topics, int $limit = 12, int $offset = 0): array
    {
        if (empty($topics)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $wheres = [];
        $params = [];
        $types  = [];

        foreach ($topics as $i => $t) {
            $key = "needle_$i";
            $wheres[] = "topics::jsonb @> :$key::jsonb";
            $params[$key] = json_encode([$t], JSON_THROW_ON_ERROR);
            $types[$key] = ParameterType::STRING;
        }

        $sql = 'SELECT DISTINCT id, created_at FROM article WHERE ' . implode(' OR ', $wheres)
             . ' ORDER BY created_at DESC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        if ($offset > 0) {
            $sql .= ' OFFSET ' . (int) $offset;
        }

        $ids = array_column($conn->fetchAllAssociative($sql, $params, $types), 'id');

        if (empty($ids)) {
            return [];
        }

        // Fetch the actual Article entities by ID
        $qb = $this->createQueryBuilder('a');
        $qb->where($qb->expr()->in('a.id', ':ids'))
            ->setParameter('ids', $ids)
            ->orderBy('a.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find latest articles from a set of authors, deduplicated by coordinate (pubkey+slug).
     *
     * Uses the composite index (pubkey, created_at DESC) for efficient lookup.
     * Fetches extra rows to compensate for deduplication, then trims to $limit.
     *
     * @param string[] $pubkeys Hex pubkeys of followed authors
     * @param int $limit Max articles to return
     * @return Article[]
     */
    public function findLatestByPubkeys(array $pubkeys, int $limit = 50): array
    {
        if (empty($pubkeys)) {
            return [];
        }

        $qb = $this->createQueryBuilder('a');
        $qb->where($qb->expr()->in('a.pubkey', ':pubkeys'))
            ->setParameter('pubkeys', $pubkeys)
            ->andWhere('a.title IS NOT NULL')
            ->andWhere('a.slug IS NOT NULL')
            ->andWhere('a.kind != :draftKind')
            ->setParameter('draftKind', KindsEnum::LONGFORM_DRAFT)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit * 2); // overfetch for deduplication

        /** @var Article[] $articles */
        $articles = $qb->getQuery()->getResult();

        // Deduplicate by coordinate (pubkey:slug) — newest revision wins
        $seen = [];
        $result = [];
        foreach ($articles as $article) {
            $key = $article->getPubkey() . ':' . $article->getSlug();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $article;
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Count distinct articles (by slug) per pubkey, in a single query.
     *
     * @param string[] $pubkeys Hex pubkeys to count articles for
     * @return array<string, int> Map of pubkey => article count
     */
    public function countArticlesByPubkeys(array $pubkeys): array
    {
        if (empty($pubkeys)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT pubkey, COUNT(DISTINCT slug) AS cnt
                FROM article
                WHERE pubkey IN (:pubkeys)
                  AND slug IS NOT NULL
                  AND title IS NOT NULL
                  AND kind != :draftKind
                GROUP BY pubkey';

        $result = $conn->executeQuery($sql, [
            'pubkeys' => $pubkeys,
            'draftKind' => KindsEnum::LONGFORM_DRAFT->value,
        ], [
            'pubkeys' => \Doctrine\DBAL\ArrayParameterType::STRING,
            'draftKind' => ParameterType::INTEGER,
        ]);

        $counts = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $counts[$row['pubkey']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Count the total number of distinct articles (by pubkey+slug coordinate)
     * across a set of pubkeys.
     *
     * @param string[] $pubkeys Hex pubkeys
     * @return int Total unique article count
     */
    public function countTotalArticlesForPubkeys(array $pubkeys): int
    {
        if (empty($pubkeys)) {
            return 0;
        }

        $conn = $this->getEntityManager()->getConnection();
        $sql = 'SELECT COUNT(*) AS cnt FROM (
                    SELECT DISTINCT pubkey, slug
                    FROM article
                    WHERE pubkey IN (:pubkeys)
                      AND slug IS NOT NULL
                      AND title IS NOT NULL
                      AND kind != :draftKind
                ) AS unique_articles';

        $result = $conn->executeQuery($sql, [
            'pubkeys' => $pubkeys,
            'draftKind' => KindsEnum::LONGFORM_DRAFT->value,
        ], [
            'pubkeys' => \Doctrine\DBAL\ArrayParameterType::STRING,
            'draftKind' => ParameterType::INTEGER,
        ]);

        return (int) $result->fetchOne();
    }

    /**
     * Find all deduplicated articles for a set of pubkeys, sorted by newest.
     * Uses PostgreSQL DISTINCT ON to keep only the latest revision per coordinate.
     *
     * @param string[] $pubkeys Hex pubkeys
     * @return Article[]
     */
    public function findAllByPubkeysDeduplicated(array $pubkeys): array
    {
        if (empty($pubkeys)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        // Use DISTINCT ON (pubkey, slug) to get only the latest revision per article
        $sql = 'SELECT id FROM (
                    SELECT DISTINCT ON (pubkey, slug) id, created_at
                    FROM article
                    WHERE pubkey IN (:pubkeys)
                      AND slug IS NOT NULL
                      AND title IS NOT NULL
                      AND kind != :draftKind
                    ORDER BY pubkey, slug, created_at DESC
                ) AS deduped
                ORDER BY created_at DESC';

        $result = $conn->executeQuery($sql, [
            'pubkeys' => $pubkeys,
            'draftKind' => KindsEnum::LONGFORM_DRAFT->value,
        ], [
            'pubkeys' => \Doctrine\DBAL\ArrayParameterType::STRING,
            'draftKind' => ParameterType::INTEGER,
        ]);

        $ids = array_column($result->fetchAllAssociative(), 'id');

        if (empty($ids)) {
            return [];
        }

        // Fetch Article entities by IDs, preserving the order
        $qb = $this->createQueryBuilder('a');
        $qb->where($qb->expr()->in('a.id', ':ids'))
            ->setParameter('ids', $ids)
            ->orderBy('a.createdAt', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find articles by pubkey (author)
     *
     * @param string $pubkey
     * @param int $limit
     * @param int $offset
     * @return Article[]
     */
    public function findByPubkey(string $pubkey, int $limit = 12, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('a');

        $qb->where('a.pubkey = :pubkey')
            // ->andWhere($qb->expr()->notLike('a.slug', ':slugPattern'))
            ->setParameter('pubkey', $pubkey)
            // ->setParameter('slugPattern', '%/%')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Get article counts grouped by tags
     *
     * @param array $tags Array of tags to count
     * @return array<string, int> Tag => count mapping
     * @throws Exception|\JsonException
     */
    public function getTagCounts(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $counts = [];

        // Normalize tags to lowercase
        $tags = array_map('strtolower', array_map('trim', $tags));
        $tags = array_values(array_unique($tags));

        foreach ($tags as $tag) {
            $sql = "SELECT COUNT(*) FROM article WHERE topics::jsonb @> :needle::jsonb";
            $count = $conn->fetchOne($sql, [
                'needle' => json_encode([$tag], JSON_THROW_ON_ERROR)
            ]);
            $counts[$tag] = (int) $count;
        }

        return $counts;
    }

    /**
     * Advanced search with filters: date range, author, tags, kind, sort.
     *
     * @param string $query   Free-text query (may be empty)
     * @param SearchFilters $filters
     * @param int $limit
     * @param int $offset
     * @return Article[]
     */
    public function advancedSearch(string $query, SearchFilters $filters, int $limit = 12, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('a');

        // Exclude slashes in slug
        $qb->andWhere($qb->expr()->notLike('a.slug', ':slugPattern'))
            ->setParameter('slugPattern', '%/%');

        // Text query (LIKE fallback)
        if (!empty($query)) {
            $searchTerm = '%' . $query . '%';
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('a.title', ':search'),
                    $qb->expr()->like('a.content', ':search'),
                    $qb->expr()->like('a.summary', ':search')
                )
            )
            ->setParameter('search', $searchTerm);
        }

        // Date from
        if ($filters->dateFrom) {
            $qb->andWhere('a.createdAt >= :dateFrom')
                ->setParameter('dateFrom', new \DateTimeImmutable($filters->dateFrom));
        }

        // Date to (end of day)
        if ($filters->dateTo) {
            $qb->andWhere('a.createdAt <= :dateTo')
                ->setParameter('dateTo', new \DateTimeImmutable($filters->dateTo . ' 23:59:59'));
        }

        // Author (hex pubkey)
        if (!empty($filters->author)) {
            $qb->andWhere('a.pubkey = :pubkey')
                ->setParameter('pubkey', $filters->author);
        }

        // Kind
        if ($filters->kind !== null) {
            $kind = KindsEnum::tryFrom($filters->kind);
            if ($kind !== null) {
                $qb->andWhere('a.kind = :kind')
                    ->setParameter('kind', $kind);
            }
        }

        // Tags — PostgreSQL jsonb containment (requires native SQL)
        $tagsArray = $filters->getTagsArray();
        if (!empty($tagsArray)) {
            return $this->advancedSearchWithTags($query, $filters, $tagsArray, $limit, $offset);
        }

        // Sort
        $sort = match ($filters->sortBy) {
            'oldest' => ['a.createdAt', 'ASC'],
            'newest' => ['a.createdAt', 'DESC'],
            default  => ['a.createdAt', 'DESC'],
        };
        $qb->orderBy($sort[0], $sort[1]);

        $qb->setFirstResult($offset)->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    /**
     * Advanced search using native SQL for jsonb tag filtering (PostgreSQL).
     */
    private function advancedSearchWithTags(string $query, SearchFilters $filters, array $tags, int $limit, int $offset): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $wheres = ["slug NOT LIKE '%/%'"];
        $params = [];
        $types = [];

        // Text query
        if (!empty($query)) {
            $wheres[] = "(title ILIKE :search OR content ILIKE :search OR summary ILIKE :search)";
            $params['search'] = '%' . $query . '%';
            $types['search'] = ParameterType::STRING;
        }

        // Date range
        if ($filters->dateFrom) {
            $wheres[] = "created_at >= :dateFrom";
            $params['dateFrom'] = $filters->dateFrom;
            $types['dateFrom'] = ParameterType::STRING;
        }
        if ($filters->dateTo) {
            $wheres[] = "created_at <= :dateTo";
            $params['dateTo'] = $filters->dateTo . ' 23:59:59';
            $types['dateTo'] = ParameterType::STRING;
        }

        // Author
        if (!empty($filters->author)) {
            $wheres[] = "pubkey = :pubkey";
            $params['pubkey'] = $filters->author;
            $types['pubkey'] = ParameterType::STRING;
        }

        // Kind
        if ($filters->kind !== null) {
            $wheres[] = "kind = :kind";
            $params['kind'] = $filters->kind;
            $types['kind'] = ParameterType::INTEGER;
        }

        // Tags (jsonb containment)
        foreach ($tags as $i => $tag) {
            $key = "tag_$i";
            $wheres[] = "topics::jsonb @> :$key::jsonb";
            $params[$key] = json_encode([$tag], JSON_THROW_ON_ERROR);
            $types[$key] = ParameterType::STRING;
        }

        // Sort
        $orderBy = match ($filters->sortBy) {
            'oldest' => 'created_at ASC',
            default  => 'created_at DESC',
        };

        $sql = 'SELECT id FROM article WHERE ' . implode(' AND ', $wheres) . ' ORDER BY ' . $orderBy;

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        if ($offset > 0) {
            $sql .= ' OFFSET ' . (int)$offset;
        }

        $ids = array_column($conn->fetchAllAssociative($sql, $params, $types), 'id');

        if (empty($ids)) {
            return [];
        }

        $qb = $this->createQueryBuilder('a');
        $qb->where($qb->expr()->in('a.id', ':ids'))
            ->setParameter('ids', $ids)
            ->orderBy('a.createdAt', $filters->sortBy === 'oldest' ? 'ASC' : 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Find articles that have comments (kind 1111), ordered by most recent comment.
     *
     * Joins the event table (comment events) against the article table via
     * the 'A' tag coordinate (kind:pubkey:slug). Deduplicates by article
     * coordinate and returns comment counts per article.
     *
     * @param int $limit Maximum articles to return
     * @param string[] $excludedPubkeys Hex pubkeys to exclude (article authors)
     * @return array{articles: Article[], commentCounts: array<string, int>}
     */
    public function findArticlesWithComments(int $limit = 50, array $excludedPubkeys = []): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // Extract the coordinate from the 'A' tag of kind 1111 events,
        // join against the article table by pubkey + slug parsed from the coordinate,
        // group by article and order by most recent comment.
        $excludeClause = '';
        $params = [];

        if (!empty($excludedPubkeys)) {
            $excludePlaceholders = [];
            foreach ($excludedPubkeys as $i => $pk) {
                $excludePlaceholders[] = ':excl_' . $i;
                $params['excl_' . $i] = $pk;
            }
            $excludeClause = 'AND a.pubkey NOT IN (' . implode(', ', $excludePlaceholders) . ')';
        }

        // Step 1: Get article coordinates with comment counts + latest comment time
        $sql = <<<SQL
            SELECT
                atag.coordinate,
                COUNT(DISTINCT e.id) AS comment_count,
                MAX(e.created_at)    AS latest_comment_at
            FROM event e
            CROSS JOIN LATERAL (
                SELECT tag->>1 AS coordinate
                FROM jsonb_array_elements(e.tags) AS tag
                WHERE tag->>0 = 'A'
                LIMIT 1
            ) AS atag
            WHERE e.kind = 1111
              AND atag.coordinate IS NOT NULL
              AND atag.coordinate LIKE '30023:%'
            GROUP BY atag.coordinate
            ORDER BY latest_comment_at DESC
            LIMIT :coord_limit
        SQL;

        $params['coord_limit'] = $limit * 3; // overfetch for dedup + exclusion
        $coordRows = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        if (empty($coordRows)) {
            return ['articles' => [], 'commentCounts' => []];
        }

        // Step 2: Parse coordinates and look up articles
        $commentCounts = [];
        $articleLookups = []; // pubkey => [slugs]
        $coordOrder = [];     // coordinate => latest_comment_at (for sorting)

        foreach ($coordRows as $row) {
            $coord = $row['coordinate'];
            $parts = explode(':', $coord, 3);
            if (count($parts) !== 3) {
                continue;
            }
            [, $pubkey, $slug] = $parts;

            // Apply exclusion
            if (!empty($excludedPubkeys) && in_array($pubkey, $excludedPubkeys, true)) {
                continue;
            }

            $commentCounts[$coord] = (int) $row['comment_count'];
            $coordOrder[$coord] = (int) $row['latest_comment_at'];
            $articleLookups[$pubkey][] = $slug;
        }

        if (empty($articleLookups)) {
            return ['articles' => [], 'commentCounts' => []];
        }

        // Step 3: Batch-fetch articles by (pubkey, slug) pairs
        $qb = $this->createQueryBuilder('a');
        $orConditions = [];
        $paramIdx = 0;
        foreach ($articleLookups as $pubkey => $slugs) {
            foreach ($slugs as $slug) {
                $pkParam = 'pk_' . $paramIdx;
                $slParam = 'sl_' . $paramIdx;
                $orConditions[] = $qb->expr()->andX(
                    $qb->expr()->eq('a.pubkey', ':' . $pkParam),
                    $qb->expr()->eq('a.slug', ':' . $slParam)
                );
                $qb->setParameter($pkParam, $pubkey);
                $qb->setParameter($slParam, $slug);
                $paramIdx++;
            }
        }

        $qb->where($qb->expr()->orX(...$orConditions))
            ->andWhere('a.title IS NOT NULL')
            ->andWhere('a.slug IS NOT NULL')
            ->andWhere('a.kind != :draftKind')
            ->setParameter('draftKind', KindsEnum::LONGFORM_DRAFT);

        /** @var Article[] $allArticles */
        $allArticles = $qb->getQuery()->getResult();

        // Step 4: Deduplicate by coordinate (pubkey:slug) — keep newest revision
        $articleMap = []; // coordinate => Article
        foreach ($allArticles as $article) {
            $coord = '30023:' . $article->getPubkey() . ':' . $article->getSlug();
            if (!isset($coordOrder[$coord])) {
                continue; // not in our result set
            }
            if (!isset($articleMap[$coord])
                || $article->getCreatedAt() > $articleMap[$coord]->getCreatedAt()) {
                $articleMap[$coord] = $article;
            }
        }

        // Step 5: Sort by latest comment time DESC
        uksort($articleMap, fn(string $a, string $b) => ($coordOrder[$b] ?? 0) <=> ($coordOrder[$a] ?? 0));

        $articles = array_values(array_slice($articleMap, 0, $limit));

        // Filter commentCounts to only the returned articles
        $finalCounts = [];
        foreach ($articles as $article) {
            $coord = '30023:' . $article->getPubkey() . ':' . $article->getSlug();
            $finalCounts[$coord] = $commentCounts[$coord] ?? 0;
        }

        return ['articles' => $articles, 'commentCounts' => $finalCounts];
    }

    /**
     * Search articles with PostgreSQL full-text search (ts_vector)
     * This is an optimized version for PostgreSQL if you have full-text indexes set up
     *
     * @param string $query
     * @param int $limit
     * @param int $offset
     * @return Article[]
     */
    public function searchByQueryPostgreSQL(string $query, int $limit = 12, int $offset = 0): array
    {
        // This requires a tsvector column in your database
        // You can uncomment and use this if you set up PostgreSQL full-text search
        /*
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT a.* FROM article a
            WHERE
                to_tsvector('english', COALESCE(a.title, '') || ' ' || COALESCE(a.content, '') || ' ' || COALESCE(a.summary, ''))
                @@ plainto_tsquery('english', :query)
                AND a.slug NOT LIKE '%/%'
            ORDER BY
                ts_rank(to_tsvector('english', COALESCE(a.title, '') || ' ' || COALESCE(a.content, '') || ' ' || COALESCE(a.summary, '')), plainto_tsquery('english', :query)) DESC,
                a.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'query' => $query,
            'limit' => $limit,
            'offset' => $offset
        ]);

        $articles = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $articles[] = $this->find($row['id']);
        }

        return $articles;
        */

        // Fallback to simple search
        return $this->searchByQuery($query, $limit, $offset);
    }
}


