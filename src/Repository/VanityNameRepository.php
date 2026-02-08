<?php

namespace App\Repository;

use App\Entity\VanityName;
use App\Enum\VanityNameStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VanityName>
 */
class VanityNameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VanityName::class);
    }

    public function save(VanityName $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(VanityName $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find by vanity name (case-insensitive)
     */
    public function findByVanityName(string $vanityName): ?VanityName
    {
        return $this->createQueryBuilder('v')
            ->where('LOWER(v.vanityName) = LOWER(:name)')
            ->setParameter('name', $vanityName)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find active vanity name by vanity name
     */
    public function findActiveByVanityName(string $vanityName): ?VanityName
    {
        return $this->createQueryBuilder('v')
            ->where('LOWER(v.vanityName) = LOWER(:name)')
            ->andWhere('v.status = :status')
            ->setParameter('name', $vanityName)
            ->setParameter('status', VanityNameStatus::ACTIVE)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find by npub
     */
    public function findByNpub(string $npub): ?VanityName
    {
        return $this->findOneBy(['npub' => $npub]);
    }

    /**
     * Find active vanity name by npub
     */
    public function findActiveByNpub(string $npub): ?VanityName
    {
        return $this->findOneBy([
            'npub' => $npub,
            'status' => VanityNameStatus::ACTIVE
        ]);
    }

    /**
     * Find by pubkey hex
     */
    public function findByPubkeyHex(string $pubkeyHex): ?VanityName
    {
        return $this->findOneBy(['pubkeyHex' => $pubkeyHex]);
    }

    /**
     * Find active vanity name by pubkey hex
     */
    public function findActiveByPubkeyHex(string $pubkeyHex): ?VanityName
    {
        return $this->findOneBy([
            'pubkeyHex' => $pubkeyHex,
            'status' => VanityNameStatus::ACTIVE
        ]);
    }

    /**
     * Get all active vanity names for NIP-05 response
     * @return VanityName[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['status' => VanityNameStatus::ACTIVE]);
    }

    /**
     * Find all vanity names, optionally filtered by status
     * @return VanityName[]
     */
    public function findAllWithStatus(?VanityNameStatus $status = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->orderBy('v.createdAt', 'DESC');

        if ($status !== null) {
            $qb->where('v.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Check if a vanity name is available (not taken by an active/pending user)
     */
    public function isAvailable(string $vanityName): bool
    {
        $count = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('LOWER(v.vanityName) = LOWER(:name)')
            ->andWhere('v.status IN (:statuses)')
            ->setParameter('name', $vanityName)
            ->setParameter('statuses', [VanityNameStatus::ACTIVE, VanityNameStatus::PENDING])
            ->getQuery()
            ->getSingleScalarResult();

        return $count === 0;
    }

    /**
     * Find vanity names that are expired and need to be released
     * @return VanityName[]
     */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.status = :status')
            ->andWhere('v.expiresAt IS NOT NULL')
            ->andWhere('v.expiresAt < :now')
            ->setParameter('status', VanityNameStatus::ACTIVE)
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getResult();
    }

    /**
     * Find pending vanity names (awaiting payment)
     * @return VanityName[]
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => VanityNameStatus::PENDING], ['createdAt' => 'DESC']);
    }

    /**
     * Search vanity names by name pattern
     * @return VanityName[]
     */
    public function search(string $query, int $limit = 20): array
    {
        return $this->createQueryBuilder('v')
            ->where('LOWER(v.vanityName) LIKE LOWER(:query)')
            ->orWhere('v.npub LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('v.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

