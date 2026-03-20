<?php

declare(strict_types=1);

namespace App\ChatBundle\Repository;

use App\ChatBundle\Entity\ChatGroup;
use App\ChatBundle\Entity\ChatPushSubscription;
use App\ChatBundle\Entity\ChatUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatPushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatPushSubscription::class);
    }

    /** @return ChatPushSubscription[] */
    public function findByUser(ChatUser $user): array
    {
        return $this->findBy(['chatUser' => $user]);
    }

    public function findByEndpoint(string $endpoint): ?ChatPushSubscription
    {
        return $this->findOneBy(['endpoint' => $endpoint]);
    }

    /**
     * Find all push subscriptions for members of a group,
     * excluding the sender and members with muted notifications.
     *
     * @return ChatPushSubscription[]
     */
    public function findForGroupNotification(ChatGroup $group, string $excludePubkey): array
    {
        return $this->createQueryBuilder('ps')
            ->join('ps.chatUser', 'u')
            ->join('App\ChatBundle\Entity\ChatGroupMembership', 'gm', 'WITH', 'gm.user = u AND gm.group = :group')
            ->where('u.pubkey != :excludePubkey')
            ->andWhere('gm.mutedNotifications = false')
            ->setParameter('group', $group)
            ->setParameter('excludePubkey', $excludePubkey)
            ->getQuery()
            ->getResult();
    }

    public function deleteByEndpoint(string $endpoint): void
    {
        $sub = $this->findByEndpoint($endpoint);
        if ($sub !== null) {
            $this->getEntityManager()->remove($sub);
            $this->getEntityManager()->flush();
        }
    }

    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('ps')
            ->delete()
            ->where('ps.expiresAt IS NOT NULL')
            ->andWhere('ps.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
