<?php

namespace App\Repository;

use App\Entity\ActiveIndexingSubscription;
use App\Enum\ActiveIndexingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActiveIndexingSubscription>
 */
class ActiveIndexingSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActiveIndexingSubscription::class);
    }

    public function findByNpub(string $npub): ?ActiveIndexingSubscription
    {
        return $this->findOneBy(['npub' => $npub]);
    }

    /**
     * Search subscriptions by npub (partial match)
     * @return ActiveIndexingSubscription[]
     */
    public function searchByNpub(string $query): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.npub LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active subscriptions (status = active or grace)
     * @return ActiveIndexingSubscription[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status IN (:statuses)')
            ->setParameter('statuses', [ActiveIndexingStatus::ACTIVE, ActiveIndexingStatus::GRACE])
            ->orderBy('s.lastFetchedAt', 'ASC') // Prioritize those not fetched recently
            ->getQuery()
            ->getResult();
    }

    /**
     * Find subscriptions that have expired but not yet moved to grace
     * @return ActiveIndexingSubscription[]
     */
    public function findExpiredNeedingGraceTransition(): array
    {
        $now = new \DateTime();

        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.expiresAt < :now')
            ->setParameter('status', ActiveIndexingStatus::ACTIVE)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find subscriptions where grace period has ended
     * @return ActiveIndexingSubscription[]
     */
    public function findGracePeriodEnded(): array
    {
        $now = new \DateTime();

        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.graceEndsAt < :now')
            ->setParameter('status', ActiveIndexingStatus::GRACE)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find subscriptions with pending invoices
     * @return ActiveIndexingSubscription[]
     */
    public function findWithPendingInvoices(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.pendingInvoiceBolt11 IS NOT NULL')
            ->setParameter('status', ActiveIndexingStatus::PENDING)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find subscriptions needing fetch (not fetched in last N minutes)
     * @return ActiveIndexingSubscription[]
     */
    public function findNeedingFetch(int $minutesSinceLastFetch = 60): array
    {
        $threshold = new \DateTime("-{$minutesSinceLastFetch} minutes");

        return $this->createQueryBuilder('s')
            ->where('s.status IN (:statuses)')
            ->andWhere('s.lastFetchedAt IS NULL OR s.lastFetchedAt < :threshold')
            ->setParameter('statuses', [ActiveIndexingStatus::ACTIVE, ActiveIndexingStatus::GRACE])
            ->setParameter('threshold', $threshold)
            ->orderBy('s.lastFetchedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics for admin dashboard
     */
    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('s');

        $total = $qb->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $active = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.status = :status')
            ->setParameter('status', ActiveIndexingStatus::ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();

        $grace = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.status = :status')
            ->setParameter('status', ActiveIndexingStatus::GRACE)
            ->getQuery()
            ->getSingleScalarResult();

        $pending = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.status = :status')
            ->setParameter('status', ActiveIndexingStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();

        $totalArticlesIndexed = $this->createQueryBuilder('s')
            ->select('SUM(s.articlesIndexed)')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        return [
            'total' => (int) $total,
            'active' => (int) $active,
            'grace' => (int) $grace,
            'pending' => (int) $pending,
            'total_articles_indexed' => (int) $totalArticlesIndexed,
        ];
    }

    public function save(ActiveIndexingSubscription $subscription, bool $flush = true): void
    {
        $this->getEntityManager()->persist($subscription);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ActiveIndexingSubscription $subscription, bool $flush = true): void
    {
        $this->getEntityManager()->remove($subscription);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
