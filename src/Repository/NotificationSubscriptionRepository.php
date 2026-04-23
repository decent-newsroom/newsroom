<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NotificationSubscription;
use App\Entity\User;
use App\Enum\NotificationSourceTypeEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NotificationSubscription>
 */
class NotificationSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationSubscription::class);
    }

    /**
     * @return NotificationSubscription[]
     */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->andWhere('s.active = true')
            ->setParameter('user', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :user')
            ->andWhere('s.active = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneForUser(User $user, NotificationSourceTypeEnum $type, string $value): ?NotificationSubscription
    {
        return $this->findOneBy([
            'user' => $user,
            'sourceType' => $type,
            'sourceValue' => $value,
        ]);
    }

    /**
     * Returns all active subscriptions matching a given type+value across all users.
     * Used by the fan-out handler to resolve the set of recipients for an incoming event.
     *
     * @return NotificationSubscription[]
     */
    public function findActiveBySourceValues(NotificationSourceTypeEnum $type, array $values): array
    {
        if ($values === []) {
            return [];
        }

        return $this->createQueryBuilder('s')
            ->andWhere('s.active = true')
            ->andWhere('s.sourceType = :type')
            ->andWhere('s.sourceValue IN (:values)')
            ->setParameter('type', $type)
            ->setParameter('values', $values)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the full active subscription set, grouped by source type, for the
     * relay worker to build REQ filters from.
     *
     * @return array{
     *     npubs: string[],
     *     publications: string[],
     *     sets: string[],
     * }
     */
    public function findAllActiveGrouped(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.sourceType AS source_type, s.sourceValue AS source_value')
            ->andWhere('s.active = true')
            ->getQuery()
            ->getScalarResult();

        $npubs = [];
        $publications = [];
        $sets = [];
        foreach ($rows as $row) {
            $type = $row['source_type'];
            $value = $row['source_value'];
            switch ($type) {
                case NotificationSourceTypeEnum::NPUB->value:
                    $npubs[$value] = true;
                    break;
                case NotificationSourceTypeEnum::PUBLICATION->value:
                    $publications[$value] = true;
                    break;
                case NotificationSourceTypeEnum::NIP51_SET->value:
                    $sets[$value] = true;
                    break;
            }
        }

        return [
            'npubs' => array_keys($npubs),
            'publications' => array_keys($publications),
            'sets' => array_keys($sets),
        ];
    }
}

