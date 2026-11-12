<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BannedPubkey;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BannedPubkey> */
class BannedPubkeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BannedPubkey::class);
    }

    public function isBanned(string $pubkey): bool
    {
        // Do not cache misses: long-running workers must see newly committed bans.
        return $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM banned_pubkey WHERE pubkey = :pubkey',
            ['pubkey' => strtolower(trim($pubkey))],
        ) !== false;
    }

    /**
     * @param list<string> $pubkeys Lowercase hexadecimal public keys
     * @return list<string>
     */
    public function findBannedPubkeys(array $pubkeys): array
    {
        $pubkeys = array_values(array_unique(array_map(
            static fn (string $pubkey): string => strtolower(trim($pubkey)),
            $pubkeys,
        )));
        if ($pubkeys === []) {
            return [];
        }

        return array_map(
            'strtolower',
            $this->getEntityManager()->getConnection()
                ->executeQuery(
                    'SELECT pubkey FROM banned_pubkey WHERE pubkey IN (:pubkeys)',
                    ['pubkeys' => $pubkeys],
                    ['pubkeys' => ArrayParameterType::STRING],
                )
                ->fetchFirstColumn(),
        );
    }
}
