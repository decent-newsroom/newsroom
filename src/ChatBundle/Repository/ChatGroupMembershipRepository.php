<?php

declare(strict_types=1);

namespace App\ChatBundle\Repository;

use App\ChatBundle\Entity\ChatGroup;
use App\ChatBundle\Entity\ChatGroupMembership;
use App\ChatBundle\Entity\ChatUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatGroupMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatGroupMembership::class);
    }

    public function findByUserAndGroup(ChatUser $user, ChatGroup $group): ?ChatGroupMembership
    {
        return $this->findOneBy(['user' => $user, 'group' => $group]);
    }

    public function isMember(ChatUser $user, ChatGroup $group): bool
    {
        return $this->findByUserAndGroup($user, $group) !== null;
    }

    /** @return ChatGroupMembership[] */
    public function findByUser(ChatUser $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    /** @return ChatGroupMembership[] */
    public function findByGroup(ChatGroup $group): array
    {
        return $this->findBy(['group' => $group]);
    }

    public function save(ChatGroupMembership $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ChatGroupMembership $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

