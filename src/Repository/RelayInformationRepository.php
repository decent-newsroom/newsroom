<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RelayInformation;
use App\Util\RelayUrlNormalizer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RelayInformation>
 */
class RelayInformationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelayInformation::class);
    }

    public function findByUrl(string $url): ?RelayInformation
    {
        return $this->find(RelayUrlNormalizer::normalize($url));
    }

    /**
     * Bulk fetch keyed by normalized URL.
     *
     * @param string[] $urls
     * @return array<string, RelayInformation>
     */
    public function findManyIndexed(array $urls): array
    {
        if ($urls === []) {
            return [];
        }
        $normalized = array_values(array_unique(array_map(
            static fn(string $u) => RelayUrlNormalizer::normalize($u),
            $urls,
        )));

        $rows = $this->createQueryBuilder('r')
            ->where('r.url IN (:urls)')
            ->setParameter('urls', $normalized)
            ->getQuery()
            ->getResult();

        $byUrl = [];
        foreach ($rows as $row) {
            /** @var RelayInformation $row */
            $byUrl[$row->getUrl()] = $row;
        }
        return $byUrl;
    }

    /**
     * @return array<string, RelayInformation>
     */
    public function findAllIndexed(): array
    {
        $byUrl = [];
        foreach ($this->findAll() as $row) {
            $byUrl[$row->getUrl()] = $row;
        }
        return $byUrl;
    }

    /**
     * Find rows whose `fetched_at` is older than the given threshold (or null).
     * Used by the periodic refresh command.
     *
     * @return RelayInformation[]
     */
    public function findStale(int $olderThanSeconds): array
    {
        $threshold = (new \DateTimeImmutable())->modify('-' . $olderThanSeconds . ' seconds');
        return $this->createQueryBuilder('r')
            ->where('r.fetchedAt IS NULL OR r.fetchedAt < :t')
            ->setParameter('t', $threshold)
            ->orderBy('r.fetchedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOrCreate(string $url): RelayInformation
    {
        $existing = $this->findByUrl($url);
        if ($existing !== null) {
            return $existing;
        }
        $entity = new RelayInformation($url);
        $this->getEntityManager()->persist($entity);
        return $entity;
    }
}

