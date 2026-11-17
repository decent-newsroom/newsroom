<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SubscriptionChange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SubscriptionChange> */
class SubscriptionChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriptionChange::class);
    }

    public function latest(string $coordinate): ?SubscriptionChange
    {
        return $this->findOneBy(['coordinate' => $coordinate], ['createdAt' => 'DESC', 'eventId' => 'ASC']);
    }
}
