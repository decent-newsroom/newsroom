<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Magazine;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MagazineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Magazine::class);
    }

    /**
     * Find a magazine by its slug
     */
    public function findBySlug(string $slug): ?Magazine
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Find all published magazines ordered by published date
     *
     * @return Magazine[]
     */
    public function findAllPublished(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.publishedAt IS NOT NULL')
            ->orderBy('m.publishedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find magazines by contributor pubkey
     *
     * @param string $pubkey Hex pubkey
     * @return Magazine[]
     */
    public function findByContributor(string $pubkey): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $platform = $connection->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            // contributors is stored as JSON; cast to jsonb and use containment.
            $ids = $connection->executeQuery(
                'SELECT id FROM magazine WHERE contributors::jsonb @> :needle::jsonb ORDER BY updated_at DESC',
                ['needle' => json_encode([$pubkey], JSON_THROW_ON_ERROR)]
            )->fetchFirstColumn();

            if ($ids === []) {
                return [];
            }

            return $this->createQueryBuilder('m')
                ->where('m.id IN (:ids)')
                ->setParameter('ids', array_map('intval', $ids))
                ->orderBy('m.updatedAt', 'DESC')
                ->getQuery()
                ->getResult();
        }

        // Fallback for non-PostgreSQL setups.
        $qb = $this->createQueryBuilder('m');

        return $qb
            ->where($qb->expr()->like('m.contributors', ':pubkey'))
            ->setParameter('pubkey', '%"' . $pubkey . '"%')
            ->orderBy('m.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recently updated magazines
     *
     * @param int $limit Maximum number of results
     * @return Magazine[]
     */
    public function findRecentlyUpdated(int $limit = 20): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find magazines by pubkey (owner)
     *
     * @param string $pubkey Hex pubkey
     * @return Magazine[]
     */
    public function findByPubkey(string $pubkey): array
    {
        return $this->findBy(['pubkey' => $pubkey], ['updatedAt' => 'DESC']);
    }

    /**
     * Save a magazine entity
     */
    public function save(Magazine $magazine, bool $flush = true): void
    {
        $this->getEntityManager()->persist($magazine);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
