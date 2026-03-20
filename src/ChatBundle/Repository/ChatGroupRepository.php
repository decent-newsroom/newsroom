<?php

declare(strict_types=1);

namespace App\ChatBundle\Repository;

use App\ChatBundle\Entity\ChatCommunity;
use App\ChatBundle\Entity\ChatGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatGroup::class);
    }

    public function findBySlugAndCommunity(string $slug, ChatCommunity $community): ?ChatGroup
    {
        return $this->findOneBy(['slug' => $slug, 'community' => $community]);
    }

    /** @return ChatGroup[] */
    public function findByCommunity(ChatCommunity $community): array
    {
        return $this->findBy(['community' => $community], ['name' => 'ASC']);
    }

    public function save(ChatGroup $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

