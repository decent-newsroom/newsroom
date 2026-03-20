<?php

declare(strict_types=1);

namespace App\ChatBundle\Repository;

use App\ChatBundle\Entity\ChatCommunity;
use App\ChatBundle\Entity\ChatCommunityMembership;
use App\ChatBundle\Entity\ChatUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatCommunityMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatCommunityMembership::class);
    }

    public function findByUserAndCommunity(ChatUser $user, ChatCommunity $community): ?ChatCommunityMembership
    {
        return $this->findOneBy(['user' => $user, 'community' => $community]);
    }

    /** @return ChatCommunityMembership[] */
    public function findByCommunity(ChatCommunity $community): array
    {
        return $this->findBy(['community' => $community]);
    }

    public function save(ChatCommunityMembership $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

