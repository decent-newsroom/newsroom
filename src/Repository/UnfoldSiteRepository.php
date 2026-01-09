<?php

namespace App\Repository;

use App\Entity\UnfoldSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UnfoldSite>
 */
class UnfoldSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnfoldSite::class);
    }

    /**
     * Find an UnfoldSite by its subdomain
     */
    public function findBySubdomain(string $subdomain): ?UnfoldSite
    {
        return $this->findOneBy(['subdomain' => $subdomain]);
    }
}

