<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Projects generic Nostr events into Event entities
 * Used for event kinds that don't have specialized projectors
 */
class GenericEventProjector
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Project a generic event from a Nostr event object
     *
     * @param object $event The Nostr event object
     * @param string $relayUrl The relay URL where the event was received
     * @return Event The persisted Event entity
     * @throws \InvalidArgumentException If event is invalid
     * @throws \Exception If database operation fails
     */
    public function projectEventFromNostrEvent(object $event, string $relayUrl): Event
    {
        // Validate event has required fields
        if (!isset($event->id) || !isset($event->kind)) {
            throw new \InvalidArgumentException('Invalid event: missing required fields (id, kind)');
        }

        // Check if event already exists
        $existing = $this->eventRepository->find($event->id);
        if ($existing) {
            $this->logger->debug('Event already exists in database', [
                'event_id' => $event->id,
                'kind' => $event->kind,
                'relay' => $relayUrl
            ]);
            return $existing;
        }

        // Create new Event entity
        $entity = new Event();
        $entity->setId($event->id);
        $entity->setEventId($event->id);
        $entity->setKind($event->kind ?? 0);
        $entity->setPubkey($event->pubkey ?? '');
        $entity->setContent($event->content ?? '');
        $entity->setCreatedAt($event->created_at ?? 0);
        $entity->setTags($event->tags ?? []);
        $entity->setSig($event->sig ?? '');

        // Persist to database
        $this->em->persist($entity);
        $this->em->flush();

        $this->logger->info('Generic event saved to database', [
            'event_id' => $event->id,
            'kind' => $event->kind,
            'pubkey' => substr($event->pubkey ?? '', 0, 16) . '...',
            'relay' => $relayUrl
        ]);

        return $entity;
    }

    /**
     * Get statistics about events by kind
     *
     * @param array<int> $kinds Optional array of kinds to filter by
     * @return array Statistics array
     */
    public function getEventStats(array $kinds = []): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('e.kind', 'COUNT(e.id) as count')
            ->from(Event::class, 'e')
            ->groupBy('e.kind')
            ->orderBy('e.kind', 'ASC');

        if (!empty($kinds)) {
            $qb->where($qb->expr()->in('e.kind', ':kinds'))
                ->setParameter('kinds', $kinds);
        }

        $results = $qb->getQuery()->getResult();

        $stats = [
            'total' => 0,
            'by_kind' => []
        ];

        foreach ($results as $result) {
            $kind = (int)$result['kind'];
            $count = (int)$result['count'];
            $stats['by_kind'][$kind] = $count;
            $stats['total'] += $count;
        }

        return $stats;
    }

    /**
     * Get count of events for a specific kind
     *
     * @param int $kind The event kind
     * @return int Event count
     */
    public function getEventCountByKind(int $kind): int
    {
        return $this->eventRepository->count(['kind' => $kind]);
    }
}
