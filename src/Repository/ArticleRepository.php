<?php

namespace App\Repository;

use App\Entity\Article;
use App\Enum\IndexStatusEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception;
use Doctrine\Persistence\ManagerRegistry;

class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
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
     */
    public function findByTopics(array $topics, int $limit = 12, int $offset = 0): array
    {
        if (empty($topics)) {
            return [];
        }

        $qb = $this->createQueryBuilder('a');

        // Use JSON contains for topics (PostgreSQL)
        // Note: This assumes topics is stored as a JSON field
        $orX = $qb->expr()->orX();
        foreach ($topics as $index => $topic) {
            $orX->add("JSONB_CONTAINS(a.topics, :topic$index) = true");
            $qb->setParameter("topic$index", json_encode([$topic]));
        }

        $qb->where($orX)
            ->andWhere($qb->expr()->notLike('a.slug', ':slugPattern'))
            ->setParameter('slugPattern', '%/%')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

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
            ->andWhere($qb->expr()->notLike('a.slug', ':slugPattern'))
            ->setParameter('pubkey', $pubkey)
            ->setParameter('slugPattern', '%/%')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
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


