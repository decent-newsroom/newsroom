<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HiddenCoordinate;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HiddenCoordinate>
 */
class HiddenCoordinateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HiddenCoordinate::class);
    }

    /**
     * @return string[]
     */
    public function findAllCoordinates(): array
    {
        return array_column(
            $this->createQueryBuilder('h')
                ->select('h.coordinate')
                ->getQuery()
                ->getScalarResult(),
            'coordinate'
        );
    }

    public function isHidden(string $coordinate): bool
    {
        return $this->findOneBy(['coordinate' => $coordinate]) !== null;
    }

    /**
     * Find hidden canonical coordinates in one bounded query.
     *
     * @param list<string> $coordinates
     * @return list<string>
     */
    public function findHiddenCoordinates(array $coordinates): array
    {
        $coordinates = array_values(array_unique(array_filter(
            $coordinates,
            static fn (string $coordinate): bool => preg_match(
                '/^3[0-9]{4}:[0-9a-f]{64}:/',
                $coordinate,
            ) === 1,
        )));
        if ($coordinates === []) {
            return [];
        }

        return $this->getEntityManager()->getConnection()
            ->executeQuery(
                'SELECT coordinate FROM hidden_coordinate WHERE coordinate IN (:coordinates)',
                ['coordinates' => $coordinates],
                ['coordinates' => ArrayParameterType::STRING],
            )
            ->fetchFirstColumn();
    }

    public function hide(string $coordinate, ?string $reason = null): HiddenCoordinate
    {
        $existing = $this->findOneBy(['coordinate' => $coordinate]);
        if ($existing) {
            if ($reason !== null) {
                $existing->setReason($reason);
            }
            return $existing;
        }

        $entity = new HiddenCoordinate($coordinate, $reason);
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();

        return $entity;
    }

    public function unhide(string $coordinate): bool
    {
        $entity = $this->findOneBy(['coordinate' => $coordinate]);
        if (!$entity) {
            return false;
        }

        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();

        return true;
    }
}
