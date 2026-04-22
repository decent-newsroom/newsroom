<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\PersistGatewayEventsMessage;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Service\EventDeletionService;
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
        private readonly DeletedEventRepository $deletedEventRepository,
        private readonly EventDeletionService $eventDeletionService,
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

            // NIP-09 shadow-ban: skip events that have been tombstoned.
            $rawKind = (int) ($rawEvent['kind'] ?? 0);
            $rawPubkey = (string) ($rawEvent['pubkey'] ?? '');
            if ($rawKind !== KindsEnum::DELETION_REQUEST->value && $rawPubkey !== '') {
                $rawDTag = null;
                if ($rawKind >= 30000 && $rawKind <= 39999) {
                    foreach (($rawEvent['tags'] ?? []) as $t) {
                        if (is_array($t) && ($t[0] ?? '') === 'd' && isset($t[1])) {
                            $rawDTag = (string) $t[1];
                            break;
                        }
                    }
                    if ($rawDTag === null) { $rawDTag = ''; }
                }
                if ($this->deletedEventRepository->isSuppressed(
                    (string) $eventId,
                    $rawKind,
                    $rawPubkey,
                    $rawDTag,
                    (int) ($rawEvent['created_at'] ?? 0),
                )) {
                    $skipped++;
                    continue;
                }
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
                    $this->processDeletionRequests($batchEntities);
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
            $this->processDeletionRequests($batchEntities);
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

    /**
     * Process NIP-09 (kind 5) deletion requests included in the persisted batch.
     * Runs after flush so the deletion event itself is already stored.
     *
     * @param Event[] $entities
     */
    private function processDeletionRequests(array $entities): void
    {
        foreach ($entities as $entity) {
            if ($entity->getKind() !== KindsEnum::DELETION_REQUEST->value) {
                continue;
            }
            try {
                $this->eventDeletionService->processDeletionRequest($entity);
            } catch (\Throwable $e) {
                $this->logger->warning('NIP-09: failed to process gateway-ingested deletion request', [
                    'event_id' => $entity->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

