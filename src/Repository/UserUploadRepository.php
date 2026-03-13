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
    public function findByNpub(string $npub, int $limit = 50, int $offset = 0, ?string $provider = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->andWhere('u.npub = :npub')
            ->setParameter('npub', $npub)
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($provider !== null) {
            $qb->andWhere('u.provider = :provider')
               ->setParameter('provider', $provider);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count total uploads for a user.
     */
    public function countByNpub(string $npub, ?string $provider = null): int
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.npub = :npub')
            ->setParameter('npub', $npub);

        if ($provider !== null) {
            $qb->andWhere('u.provider = :provider')
               ->setParameter('provider', $provider);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}

