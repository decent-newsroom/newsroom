<?php

namespace App\Repository;

use App\Entity\Nzine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Nzine>
 */
class NzineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Nzine::class);
    }

    /**
     * Find all nzines with RSS feeds configured and in published state
     *
     * @return Nzine[]
     */
    public function findActiveRssNzines(): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.feedUrl IS NOT NULL')
            ->andWhere('n.state = :state')
            ->setParameter('state', 'published')
            ->orderBy('n.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a specific nzine by ID with RSS feed configured
     */
    public function findRssNzineById(int $id): ?Nzine
    {
        return $this->createQueryBuilder('n')
            ->where('n.id = :id')
            ->andWhere('n.feedUrl IS NOT NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    //    /**
    //     * @return Nzine[] Returns an array of Nzine objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('j')
    //            ->andWhere('j.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('j.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Nzine
    //    {
    //        return $this->createQueryBuilder('j')
    //            ->andWhere('j.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
