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
     * Find highlights for a specific article coordinate
     */
    public function findByArticleCoordinate(string $articleCoordinate): array
    {
        $qb = $this->createQueryBuilder('h')
            ->where('h.articleCoordinate = :coordinate')
            ->setParameter('coordinate', $articleCoordinate)
            ->orderBy('h.createdAt', 'DESC');

        // Debug: log the query and parameters
        error_log('Querying highlights for coordinate: ' . $articleCoordinate);
        error_log('SQL: ' . $qb->getQuery()->getSQL());

        return $qb->getQuery()->getResult();
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

        $count = $this->createQueryBuilder('h')
            ->select('COUNT(h.id)')
            ->where('h.articleCoordinate = :coordinate')
            ->andWhere('h.cachedAt > :cutoff')
            ->setParameter('coordinate', $articleCoordinate)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();

        return $count === 0;
    }

    /**
     * Get the most recent cache time for an article
     */
    public function getLastCacheTime(string $articleCoordinate): ?\DateTimeImmutable
    {
        $result = $this->createQueryBuilder('h')
            ->select('h.cachedAt')
            ->where('h.articleCoordinate = :coordinate')
            ->setParameter('coordinate', $articleCoordinate)
            ->orderBy('h.cachedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result ? $result['cachedAt'] : null;
    }

    /**
     * Delete old highlights for an article (before refreshing)
     */
    public function deleteOldHighlights(string $articleCoordinate, \DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('h')
            ->delete()
            ->where('h.articleCoordinate = :coordinate')
            ->andWhere('h.cachedAt < :before')
            ->setParameter('coordinate', $articleCoordinate)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}

