<?php

declare(strict_types=1);

namespace App\ChatBundle\Repository;

use App\ChatBundle\Entity\ChatCommunity;
use App\ChatBundle\Entity\ChatUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatUser::class);
    }

    public function findByPubkeyAndCommunity(string $pubkey, ChatCommunity $community): ?ChatUser
    {
        return $this->findOneBy(['pubkey' => $pubkey, 'community' => $community]);
    }

    /** @return ChatUser[] */
    public function findByCommunity(ChatCommunity $community): array
    {
        return $this->findBy(['community' => $community], ['displayName' => 'ASC']);
    }

    /** @return ChatUser[] */
    public function findByPubkeys(array $pubkeys, ChatCommunity $community): array
    {
        if (empty($pubkeys)) {
            return [];
        }
        return $this->createQueryBuilder('u')
            ->where('u.community = :community')
            ->andWhere('u.pubkey IN (:pubkeys)')
            ->setParameter('community', $community)
            ->setParameter('pubkeys', $pubkeys)
            ->getQuery()
            ->getResult();
    }

    public function save(ChatUser $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

