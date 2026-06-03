<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EssayistZapClaim;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EssayistZapClaim>
 */
class EssayistZapClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EssayistZapClaim::class);
    }

    public function save(EssayistZapClaim $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function delete(EssayistZapClaim $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find a claim by zap receipt event ID.
     */
    public function findByZapReceiptEventId(string $eventId): ?EssayistZapClaim
    {
        return $this->findOneBy(['zapReceiptEventId' => $eventId]);
    }

    /**
     * Find a claim by BOLT11 invoice.
     */
    public function findByBolt11(string $bolt11): ?EssayistZapClaim
    {
        return $this->findOneBy(['bolt11Invoice' => $bolt11]);
    }

    /**
     * Find pending claims for a user.
     *
     * @return EssayistZapClaim[]
     */
    public function findPendingForUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'pending')
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all pending claims (for admin review).
     *
     * @return EssayistZapClaim[]
     */
    public function findAllPending(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :status')
            ->setParameter('status', 'pending')
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return EssayistZapClaim[]
     */
    public function findPendingForSponsorPubkey(string $sponsorPubkeyHex): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.sponsorPubkey = :sponsor')
            ->andWhere('c.status = :status')
            ->setParameter('sponsor', $sponsorPubkeyHex)
            ->setParameter('status', 'pending')
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recently verified claims (age in days).
     *
     * @return EssayistZapClaim[]
     */
    public function findRecentlyVerified(int $daysAgo = 7): array
    {
        $since = (new \DateTimeImmutable())->modify("-{$daysAgo} days");
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :status')
            ->andWhere('c.verifiedAt >= :since')
            ->setParameter('status', 'verified')
            ->setParameter('since', $since)
            ->orderBy('c.verifiedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}

