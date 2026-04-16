<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Event;
use App\Message\PersistGatewayEventsMessage;
use App\Repository\EventRepository;
use App\Service\Graph\EventIngestionListener;
use App\Service\ReplaceableEventCleanupService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Persists raw Nostr events received by the relay gateway to the Event table.
 *
 * Uses batch deduplication (single SELECT for all incoming IDs) and flushes
 * in batches of 50 to keep memory bounded. Also updates the graph layer
 * (parsed_reference + current_record) for every newly persisted event.
 * After each flush, cleans up older versions of replaceable events (NIP-01).
 */
#[AsMessageHandler]
final class PersistGatewayEventsHandler
{
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventIngestionListener $eventIngestionListener,
        private readonly ReplaceableEventCleanupService $cleanupService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PersistGatewayEventsMessage $message): void
    {
        $rawEvents = $message->events;

        if ($rawEvents === []) {
            return;
        }

        // Collect all event IDs to batch-check existence in a single query
        $incomingIds = array_filter(array_column($rawEvents, 'id'));
        if ($incomingIds === []) {
            return;
        }

        $existingIds = $this->eventRepository->findExistingIds($incomingIds);

        $persisted = 0;
        $skipped = 0;
        $batch = 0;
        /** @var Event[] $batchEntities */
        $batchEntities = [];

        foreach ($rawEvents as $rawEvent) {
            if (!is_array($rawEvent)) {
                continue;
            }

            $eventId = $rawEvent['id'] ?? null;
            if (!$eventId || isset($existingIds[$eventId])) {
                $skipped++;
                continue;
            }

            try {
                $entity = $this->buildEntity($rawEvent);
                $this->entityManager->persist($entity);

                // Update graph layer tables (uses DBAL, safe before flush)
                try {
                    $this->eventIngestionListener->processEvent($entity);
                } catch (\Throwable) {}

                $batchEntities[] = $entity;
                $persisted++;
                $batch++;

                if ($batch >= self::BATCH_SIZE) {
                    $this->entityManager->flush();
                    $this->cleanupReplaceableEvents($batchEntities);
                    $batchEntities = [];
                    $batch = 0;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('PersistGatewayEvents: failed to persist event', [
                    'event_id' => $eventId,
                    'kind'     => $rawEvent['kind'] ?? '?',
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // Final flush
        if ($batch > 0) {
            $this->entityManager->flush();
            $this->cleanupReplaceableEvents($batchEntities);
        }

        if ($persisted > 0) {
            $this->logger->info('PersistGatewayEvents: persisted events', [
                'persisted' => $persisted,
                'skipped'   => $skipped,
                'total'     => count($rawEvents),
            ]);
        }
    }

    private function buildEntity(array $raw): Event
    {
        $entity = new Event();
        $entity->setId($raw['id']);
        $entity->setKind((int) ($raw['kind'] ?? 0));
        $entity->setPubkey($raw['pubkey'] ?? '');
        $entity->setContent($raw['content'] ?? '');
        $entity->setCreatedAt((int) ($raw['created_at'] ?? time()));
        $entity->setTags($raw['tags'] ?? []);
        $entity->setSig($raw['sig'] ?? '');
        $entity->extractAndSetDTag();

        return $entity;
    }

    /**
     * Clean up older versions of replaceable events after a batch flush.
     *
     * @param Event[] $entities
     */
    private function cleanupReplaceableEvents(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->cleanupService->removeOlderEventVersions($entity, $this->entityManager);
        }
    }
}

