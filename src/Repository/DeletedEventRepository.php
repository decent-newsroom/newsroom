<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeletedEvent;
use App\Entity\Event;
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

    /**
     * Batch NIP-09 checks for already-selected local events.
     *
     * @param list<Event> $events
     * @return list<string> Suppressed event IDs, lowercase
     */
    public function findSuppressedEventIds(array $events): array
    {
        $references = [];
        foreach ($events as $event) {
            $references[] = strtolower($event->getId());
            if ($event->getKind() >= 30000 && $event->getKind() <= 39999) {
                $references[] = $event->getKind() . ':' . strtolower($event->getPubkey()) . ':' . $this->dTagFromEvent($event);
            }
        }
        $references = array_values(array_unique($references));
        if ($references === []) {
            return [];
        }

        /** @var DeletedEvent[] $tombstones */
        $tombstones = $this->findBy(['targetRef' => $references]);
        $byReference = [];
        foreach ($tombstones as $tombstone) {
            // Event IDs are hex, but coordinate identifiers are case-sensitive
            // and may contain colons. Keep the queried coordinate intact.
            $reference = $tombstone->getRefType() === DeletedEvent::REF_EVENT_ID
                ? strtolower($tombstone->getTargetRef())
                : $tombstone->getTargetRef();
            $byReference[$reference][] = $tombstone;
        }

        $suppressedIds = [];
        foreach ($events as $event) {
            $pubkey = strtolower($event->getPubkey());
            $eventId = strtolower($event->getId());
            foreach ($byReference[$eventId] ?? [] as $tombstone) {
                if ($tombstone->getRefType() === DeletedEvent::REF_EVENT_ID
                    && strtolower($tombstone->getPubkey()) === $pubkey) {
                    $suppressedIds[$eventId] = true;
                    break;
                }
            }

            if (isset($suppressedIds[$eventId]) || $event->getKind() < 30000 || $event->getKind() > 39999) {
                continue;
            }
            $coordinate = $event->getKind() . ':' . $pubkey . ':' . $this->dTagFromEvent($event);
            foreach ($byReference[$coordinate] ?? [] as $tombstone) {
                if ($tombstone->getRefType() === DeletedEvent::REF_COORDINATE
                    && strtolower($tombstone->getPubkey()) === $pubkey
                    && $event->getCreatedAt() <= $tombstone->getDeletionCreatedAt()) {
                    $suppressedIds[$eventId] = true;
                    break;
                }
            }
        }

        return array_keys($suppressedIds);
    }

    private function dTagFromEvent(Event $event): string
    {
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'd') {
                return is_string($tag[1] ?? null) ? $tag[1] : '';
            }
        }

        return '';
    }

    public function save(DeletedEvent $deletedEvent, bool $flush = true): void
    {
        $this->getEntityManager()->persist($deletedEvent);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
