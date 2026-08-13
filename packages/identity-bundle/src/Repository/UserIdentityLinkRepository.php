<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Repository;

use DecentNewsroom\IdentityBundle\Entity\UserIdentityLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserIdentityLink>
 */
class UserIdentityLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserIdentityLink::class);
    }

    public function findOneByProviderAndExternalId(string $provider, string $externalId): ?UserIdentityLink
    {
        return $this->findOneBy(['provider' => $provider, 'externalId' => $externalId]);
    }

    /**
     * @return UserIdentityLink[]
     */
    public function findAllByOwnerId(string $ownerId): array
    {
        return $this->findBy(['ownerId' => $ownerId], ['createdAt' => 'ASC']);
    }

    public function countByOwnerId(string $ownerId): int
    {
        return (int) $this->count(['ownerId' => $ownerId]);
    }
}
