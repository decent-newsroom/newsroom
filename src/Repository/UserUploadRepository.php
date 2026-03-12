<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserUpload;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserUpload>
 */
class UserUploadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserUpload::class);
    }

    /**
     * Get uploads for a user, newest first.
     *
     * @return UserUpload[]
     */
    public function findByNpub(string $npub, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.npub = :npub')
            ->setParameter('npub', $npub)
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count total uploads for a user.
     */
    public function countByNpub(string $npub): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.npub = :npub')
            ->setParameter('npub', $npub)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

