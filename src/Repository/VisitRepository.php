<?php

namespace App\Repository;

use App\Entity\Visit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function getVisitCountByRoute(): array
    {
        return $this->createQueryBuilder('v')
            ->select('v.route, COUNT(v.id) as count')
            ->groupBy('v.route')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
