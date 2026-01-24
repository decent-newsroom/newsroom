<?php

namespace App\Service;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Projects Nostr media events (kinds 20, 21, 22) into the database
 * Handles the conversion from event format to Event entity and persistence
 */
class MediaEventProjector
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Project a Nostr media event into the database
     * Creates or updates an Event entity from the media event data
     *
     * @param object $event The Nostr event object (stdClass with id, kind, pubkey, content, tags, etc.)
     * @param string $relayUrl The relay URL where the event was received from
     * @throws \InvalidArgumentException if event is not a valid media event
     * @throws \Exception
     */
    public function projectMediaFromEvent(object $event, string $relayUrl): void
    {
        // Validate that this is a media event (kinds 20, 21, 22)
        if (!isset($event->kind) || !in_array($event->kind, [20, 21, 22])) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid media event kind: %s. Expected 20, 21, or 22.',
                $event->kind ?? 'null'
            ));
        }

        // Validate required fields
        if (!isset($event->id) || !isset($event->pubkey)) {
            throw new \InvalidArgumentException('Media event missing required fields (id, pubkey)');
        }

        try {
            // Check if event already exists in the database
            $existingEvent = $this->eventRepository->findById($event->id);

            if ($existingEvent) {
                // Event already exists, skip (events are immutable in Nostr)
                $this->logger->debug('Media event already exists in database, skipping', [
                    'event_id' => $event->id,
                    'kind' => $event->kind,
                    'db_id' => $existingEvent->getId()
                ]);
                return;
            }

            // Create new Event entity
            $mediaEvent = new Event();
            $mediaEvent->setId($event->id);
            $mediaEvent->setPubkey($event->pubkey);
            $mediaEvent->setCreatedAt($event->created_at ?? time());
            $mediaEvent->setKind($event->kind);
            $mediaEvent->setTags($event->tags ?? []);
            $mediaEvent->setContent($event->content ?? '');
            $mediaEvent->setSig($event->sig ?? '');

            $this->logger->info('Persisting new media event from relay', [
                'event_id' => $event->id,
                'kind' => $event->kind,
                'pubkey' => substr($event->pubkey, 0, 16) . '...',
                'has_url' => $mediaEvent->getMediaUrl() !== null,
                'is_nsfw' => $mediaEvent->isNSFW(),
                'relay' => $relayUrl
            ]);

            // Persist the event
            $this->entityManager->persist($mediaEvent);
            $this->entityManager->flush();

            $this->logger->info('Media event successfully saved to database', [
                'event_id' => $event->id,
                'kind' => $event->kind
            ]);

        } catch (\InvalidArgumentException $e) {
            // Re-throw validation errors
            throw $e;
        } catch (\Exception $e) {
            // Log and re-throw database errors
            $this->logger->error('Failed to persist media event', [
                'event_id' => $event->id ?? 'unknown',
                'kind' => $event->kind ?? 'unknown',
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw $e;
        }
    }

    /**
     * Get statistics about persisted media events
     *
     * @return array{total: int, by_kind: array<int, int>}
     */
    public function getMediaStats(): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        // Get total count
        $total = (int) $qb->select('COUNT(e.id)')
            ->from(Event::class, 'e')
            ->where($qb->expr()->in('e.kind', [20, 21, 22]))
            ->getQuery()
            ->getSingleScalarResult();

        // Get count by kind
        $qb = $this->entityManager->createQueryBuilder();
        $results = $qb->select('e.kind', 'COUNT(e.id) as count')
            ->from(Event::class, 'e')
            ->where($qb->expr()->in('e.kind', [20, 21, 22]))
            ->groupBy('e.kind')
            ->getQuery()
            ->getResult();

        $byKind = [];
        foreach ($results as $result) {
            $byKind[$result['kind']] = (int) $result['count'];
        }

        return [
            'total' => $total,
            'by_kind' => $byKind
        ];
    }
}
