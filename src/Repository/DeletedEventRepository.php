<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeletedEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeletedEvent>
 */
class DeletedEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeletedEvent::class);
    }

    public function findByTargetRef(string $targetRef): ?DeletedEvent
    {
        return $this->findOneBy(['targetRef' => $targetRef]);
    }

    /**
     * Is the given raw incoming event suppressed by an existing NIP-09 tombstone?
     *
     * Rules (NIP-09):
     *   - Deletion requests MUST be honored only when the target author == requester.
     *   - For `e`-tag tombstones: any event with the matching id from the same pubkey is suppressed.
     *   - For `a`-tag tombstones (replaceable): any version of the coordinate with
     *     `created_at <= deletionCreatedAt` from the same pubkey is suppressed.
     */
    public function isSuppressed(
        string $eventId,
        int $kind,
        string $pubkey,
        ?string $dTag,
        int $createdAt,
    ): bool {
        // Event-id match
        $byId = $this->findOneBy(['targetRef' => $eventId, 'refType' => DeletedEvent::REF_EVENT_ID]);
        if ($byId !== null && $byId->getPubkey() === $pubkey) {
            return true;
        }

        // Coordinate match for parameterized replaceable events
        if ($kind >= 30000 && $kind <= 39999) {
            $coord = $kind . ':' . $pubkey . ':' . ($dTag ?? '');
            $byCoord = $this->findOneBy(['targetRef' => $coord, 'refType' => DeletedEvent::REF_COORDINATE]);
            if ($byCoord !== null
                && $byCoord->getPubkey() === $pubkey
                && $createdAt <= $byCoord->getDeletionCreatedAt()) {
                return true;
            }
        }

        // Non-parameterized replaceable: stored as "kind::pubkey"? Skip — rare for kind 5 targets.
        return false;
    }

    public function save(DeletedEvent $deletedEvent, bool $flush = true): void
    {
        $this->getEntityManager()->persist($deletedEvent);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

