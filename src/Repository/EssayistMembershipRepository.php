<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EssayistMembership;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EssayistMembership>
 */
class EssayistMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EssayistMembership::class);
    }

    public function findByZapReceiptId(string $eventId): ?EssayistMembership
    {
        return $this->findOneBy(['zapReceiptEventId' => $eventId]);
    }

    public function findLatestForUser(User $user): ?EssayistMembership
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.expiresAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return EssayistMembership[]
     */
    public function findExpiredWithActiveRole(\DateTimeImmutable $now): array
    {
        // Memberships whose latest expiry is in the past — caller filters by
        // ROLE_ESSAYIST_MEMBER presence on the user to avoid double-revocation.
        $sub = $this->createQueryBuilder('m2')
            ->select('IDENTITY(m2.user) AS uid, MAX(m2.expiresAt) AS max_exp')
            ->groupBy('m2.user')
            ->getQuery()
            ->getResult();

        $expiredUserIds = [];
        foreach ($sub as $row) {
            if ($row['max_exp'] instanceof \DateTimeInterface && $row['max_exp'] < $now) {
                $expiredUserIds[] = (int) $row['uid'];
            }
        }

        if (empty($expiredUserIds)) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->andWhere('m.user IN (:ids)')
            ->setParameter('ids', $expiredUserIds)
            ->orderBy('m.expiresAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

