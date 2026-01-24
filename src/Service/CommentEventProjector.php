<?php

namespace App\Service;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Projects Nostr comment and zap events (kinds 1111, 9735) into the database
 * Handles the conversion from event format to Event entity and persistence
 */
class CommentEventProjector
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Project a Nostr comment event (kind 1111) into the database
     *
     * @param object $event The Nostr event object (stdClass with id, kind, pubkey, content, tags, etc.)
     * @throws \InvalidArgumentException if event is not a valid comment event
     */
    public function projectCommentFromEvent(object $event): void
    {
        // Validate that this is a comment event (kind 1111)
        if (!isset($event->kind) || $event->kind !== 1111) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid comment event kind: %s. Expected 1111.',
                $event->kind ?? 'null'
            ));
        }

        $this->projectEvent($event, 'comment');
    }

    /**
     * Project a Nostr zap receipt event (kind 9735) into the database
     *
     * @param object $event The Nostr event object (stdClass with id, kind, pubkey, content, tags, etc.)
     * @throws \InvalidArgumentException if event is not a valid zap event
     */
    public function projectZapFromEvent(object $event): void
    {
        // Validate that this is a zap receipt event (kind 9735)
        if (!isset($event->kind) || $event->kind !== 9735) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid zap receipt event kind: %s. Expected 9735.',
                $event->kind ?? 'null'
            ));
        }

        $this->projectEvent($event, 'zap');
    }

    /**
     * Project multiple comment/zap events into the database in batch
     *
     * @param array $events Array of Nostr event objects
     * @return int Number of events successfully persisted
     */
    public function projectEvents(array $events): int
    {
        $persistedCount = 0;

        foreach ($events as $event) {
            try {
                if (!isset($event->kind)) {
                    continue;
                }

                if ($event->kind === 1111) {
                    $this->projectCommentFromEvent($event);
                    $persistedCount++;
                } elseif ($event->kind === 9735) {
                    $this->projectZapFromEvent($event);
                    $persistedCount++;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to project event', [
                    'event_id' => $event->id ?? 'unknown',
                    'kind' => $event->kind ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Flush all persisted events at once
        if ($persistedCount > 0) {
            try {
                $this->entityManager->flush();
                $this->logger->info('Batch persisted comment/zap events', [
                    'count' => $persistedCount
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to flush comment/zap events', [
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        return $persistedCount;
    }

    /**
     * Internal method to project any comment/zap event
     *
     * @param object $event The Nostr event object
     * @param string $type Event type for logging ('comment' or 'zap')
     */
    private function projectEvent(object $event, string $type): void
    {
        // Validate required fields
        if (!isset($event->id) || !isset($event->pubkey)) {
            throw new \InvalidArgumentException(sprintf(
                '%s event missing required fields (id, pubkey)',
                ucfirst($type)
            ));
        }

        try {
            // Check if event already exists in the database
            $existingEvent = $this->eventRepository->findById($event->id);

            if ($existingEvent) {
                // Event already exists, skip (events are immutable in Nostr)
                $this->logger->debug(sprintf('%s event already exists in database, skipping', ucfirst($type)), [
                    'event_id' => $event->id,
                    'kind' => $event->kind,
                ]);
                return;
            }

            // Extract coordinate from A tag for logging
            $coordinate = null;
            if (isset($event->tags) && is_array($event->tags)) {
                foreach ($event->tags as $tag) {
                    if (is_array($tag) && count($tag) >= 2 && $tag[0] === 'A') {
                        $coordinate = $tag[1];
                        break;
                    }
                }
            }

            // Create new Event entity
            $commentEvent = new Event();
            $commentEvent->setId($event->id);
            $commentEvent->setPubkey($event->pubkey);
            $commentEvent->setCreatedAt($event->created_at ?? time());
            $commentEvent->setKind($event->kind);
            $commentEvent->setTags($event->tags ?? []);
            $commentEvent->setContent($event->content ?? '');
            $commentEvent->setSig($event->sig ?? '');

            $this->logger->info(sprintf('Persisting new %s event', $type), [
                'event_id' => $event->id,
                'kind' => $event->kind,
                'pubkey' => substr($event->pubkey, 0, 16) . '...',
                'coordinate' => $coordinate,
                'created_at' => $event->created_at ?? time(),
            ]);

            // Persist the event (without immediate flush for batch operations)
            $this->entityManager->persist($commentEvent);

        } catch (\InvalidArgumentException $e) {
            // Re-throw validation errors
            throw $e;
        } catch (\Exception $e) {
            // Log and re-throw database errors
            $this->logger->error(sprintf('Failed to persist %s event', $type), [
                'event_id' => $event->id ?? 'unknown',
                'kind' => $event->kind ?? 'unknown',
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            throw $e;
        }
    }

    /**
     * Flush any pending comment/zap events to the database
     */
    public function flush(): void
    {
        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->error('Failed to flush comment/zap events', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
