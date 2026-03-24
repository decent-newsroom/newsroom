<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HiddenCoordinate;
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

