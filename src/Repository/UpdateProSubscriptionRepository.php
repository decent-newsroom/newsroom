<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UpdateProSubscription;
use App\Enum\ActiveIndexingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UpdateProSubscription>
 */
class UpdateProSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UpdateProSubscription::class);
    }

    public function findByNpub(string $npub): ?UpdateProSubscription
    {
        return $this->findOneBy(['npub' => $npub]);
    }

    /** @return UpdateProSubscription[] */
    public function findWithPendingInvoices(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.pendingInvoiceBolt11 IS NOT NULL')
            ->setParameter('status', ActiveIndexingStatus::PENDING)
            ->getQuery()
            ->getResult();
    }

    /** @return UpdateProSubscription[] */
    public function findExpiredNeedingGraceTransition(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.expiresAt < :now')
            ->setParameter('status', ActiveIndexingStatus::ACTIVE)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    /** @return UpdateProSubscription[] */
    public function findGracePeriodEnded(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.graceEndsAt < :now')
            ->setParameter('status', ActiveIndexingStatus::GRACE)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    public function save(UpdateProSubscription $subscription, bool $flush = true): void
    {
        $this->getEntityManager()->persist($subscription);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(UpdateProSubscription $subscription, bool $flush = true): void
    {
        $this->getEntityManager()->remove($subscription);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

