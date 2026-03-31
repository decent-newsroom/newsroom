<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserRelayList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserRelayList>
 */
class UserRelayListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserRelayList::class);
    }

    /**
     * Find the relay list for a single pubkey.
     */
    public function findByPubkey(string $pubkeyHex): ?UserRelayList
    {
        return $this->findOneBy(['pubkey' => $pubkeyHex]);
    }

    /**
     * Batch-resolve relay lists for multiple pubkeys.
     *
     * @param string[] $pubkeys Hex pubkeys
     * @return array<string, UserRelayList> pubkey => UserRelayList
     */
    public function findByPubkeys(array $pubkeys): array
    {
        if (empty($pubkeys)) {
            return [];
        }

        $entities = $this->createQueryBuilder('r')
            ->where('r.pubkey IN (:pubkeys)')
            ->setParameter('pubkeys', $pubkeys)
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($entities as $entity) {
            $result[$entity->getPubkey()] = $entity;
        }

        return $result;
    }
}

