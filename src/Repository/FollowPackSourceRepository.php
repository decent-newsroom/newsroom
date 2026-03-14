<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FollowPackSource;
use App\Enum\FollowPackPurpose;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FollowPackSource>
 */
class FollowPackSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FollowPackSource::class);
    }

    public function findByPurpose(FollowPackPurpose $purpose): ?FollowPackSource
    {
        return $this->findOneBy(['purpose' => $purpose]);
    }

    /**
     * @return FollowPackSource[]
     */
    public function findAll(): array
    {
        return $this->findBy([], ['purpose' => 'ASC']);
    }
}

