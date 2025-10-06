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
}
