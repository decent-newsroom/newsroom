<?php

declare(strict_types=1);

namespace App\ChatBundle\Repository;

use App\ChatBundle\Entity\ChatInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatInvite::class);
    }

    public function findByTokenHash(string $tokenHash): ?ChatInvite
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function save(ChatInvite $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

