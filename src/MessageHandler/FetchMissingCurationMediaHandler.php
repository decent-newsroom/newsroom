<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Event;
use App\Message\FetchMissingCurationMediaMessage;
use App\Repository\EventRepository;
use App\Service\Graph\EventIngestionListener;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\UserRelayListService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FetchMissingCurationMediaHandler
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventIngestionListener $eventIngestionListener,
        private readonly HubInterface $hub,
        private readonly UserRelayListService $userRelayListService,
        private readonly RelayRegistry $relayRegistry,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(FetchMissingCurationMediaMessage $message): void
    {
        $eventIds = array_values(array_unique(array_filter($message->eventIds)));
        $coordinates = array_values(array_unique(array_filter($message->coordinates)));

        if ($eventIds === [] && $coordinates === []) {
            return;
        }

        // Build relay list: author's declared relays + hint relays + local relay
        $relays = $this->buildRelayList($message);
        $persisted = [];

        // ── Fetch by event ID (e-tags) ──────────────────────────────────
        if ($eventIds !== []) {
            $existingIds = array_map(
                static fn (Event $event) => $event->getId(),
                $this->eventRepository->findBy(['id' => $eventIds])
            );
            $missingIds = array_values(array_diff($eventIds, $existingIds));

            if ($missingIds !== []) {
                $this->logger->info('Fetching missing curation media by event ID', [
                    'curation_id' => $message->curationId,
                    'missing_count' => count($missingIds),
                    'missing_ids' => array_map(fn(string $id) => substr($id, 0, 12) . '…', $missingIds),
                    'relay_count' => count($relays),
                    'relays' => $relays,
                ]);

                try {
                    $fetchedEvents = $this->nostrClient->getEventsByIds($missingIds, $relays);
                    $this->logger->info('Curation media fetch by ID completed', [
                        'curation_id' => $message->curationId,
                        'fetched_count' => count($fetchedEvents),
                        'still_missing' => count($missingIds) - count($fetchedEvents),
                    ]);

                    foreach ($fetchedEvents as $rawEvent) {
                        $entity = $this->persistRawEvent($rawEvent);
                        if ($entity) {
                            $persisted[] = $entity;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to fetch missing curation media by ID', [
                        'curation_id' => $message->curationId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // ── Fetch by coordinate (a-tags) ────────────────────────────────
        if ($coordinates !== []) {
            // Check which coordinates are already in the DB
            $missingCoords = [];
            foreach ($coordinates as $coord) {
                $parts = explode(':', $coord, 3);
                if (count($parts) !== 3) {
                    continue;
                }
                [$kind, $pubkey, $identifier] = $parts;
                $existing = $this->eventRepository->findByNaddr((int)$kind, $pubkey, $identifier);
                if (!$existing) {
                    $missingCoords[] = $coord;
                }
            }

            if ($missingCoords !== []) {
                $this->logger->info('Fetching missing curation media by coordinate', [
                    'curation_id' => $message->curationId,
                    'missing_count' => count($missingCoords),
                    'coordinates' => $missingCoords,
                    'relay_count' => count($relays),
                    'relays' => $relays,
                ]);

                try {
                    $fetchedByCoord = $this->nostrClient->getEventsByCoordinates($missingCoords, $relays);
                    $this->logger->info('Curation media fetch by coordinate completed', [
                        'curation_id' => $message->curationId,
                        'fetched_count' => count($fetchedByCoord),
                        'still_missing' => count($missingCoords) - count($fetchedByCoord),
                    ]);

                    foreach ($fetchedByCoord as $rawEvent) {
                        $entity = $this->persistRawEvent($rawEvent);
                        if ($entity) {
                            $persisted[] = $entity;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to fetch missing curation media by coordinate', [
                        'curation_id' => $message->curationId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Store fetch metadata for the sync-status endpoint
        $this->recordFetchAttempt($message->curationId, $eventIds, $coordinates, $relays);

        if ($persisted === []) {
            return;
        }

        $this->entityManager->flush();

        foreach ($persisted as $entity) {
            try {
                $this->eventIngestionListener->processEvent($entity);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to update graph tables for curation media event', [
                    'event_id' => $entity->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->publish($message->curationId, $persisted);
    }

    /**
     * Persist a raw event object if it doesn't already exist in the DB.
     */
    private function persistRawEvent(object $rawEvent): ?Event
    {
        $eventId = $rawEvent->id ?? null;
        if (!is_string($eventId) || $eventId === '' || $this->eventRepository->find($eventId) !== null) {
            return null;
        }

        $entity = new Event();
        $entity->setId($eventId);
        $entity->setKind((int) ($rawEvent->kind ?? 0));
        $entity->setPubkey((string) ($rawEvent->pubkey ?? ''));
        $entity->setContent((string) ($rawEvent->content ?? ''));
        $entity->setCreatedAt((int) ($rawEvent->created_at ?? time()));
        $entity->setTags($this->normalizeTags($rawEvent->tags ?? []));
        $entity->setSig((string) ($rawEvent->sig ?? ''));
        $entity->extractAndSetDTag();

        $this->entityManager->persist($entity);
        return $entity;
    }

    /**
     * Build a comprehensive relay list for fetching missing media:
     *   1. Board author's write relays (kind 10002) — most likely source
     *   2. Relay hints from e-tags and a-tags in the curation event
     *   3. Local relay as baseline
     */
    private function buildRelayList(FetchMissingCurationMediaMessage $message): array
    {
        $relays = [];

        // 1. Author's declared relays (the board creator's write relays)
        if ($message->authorPubkey !== '') {
            try {
                $authorRelayList = $this->userRelayListService->getRelayList($message->authorPubkey);
                // Prefer write relays (author likely published media to these)
                $authorRelays = !empty($authorRelayList['write'])
                    ? $authorRelayList['write']
                    : ($authorRelayList['all'] ?? []);
                $relays = array_merge($relays, $authorRelays);
            } catch (\Throwable $e) {
                $this->logger->debug('Could not resolve author relay list for curation media fetch', [
                    'author_pubkey' => substr($message->authorPubkey, 0, 12) . '…',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2. Hint relays from e-tags
        if (!empty($message->relays)) {
            $relays = array_merge($relays, $message->relays);
        }

        // 3. Ensure local relay is included
        $localRelay = $this->relayRegistry->getLocalRelay();
        if ($localRelay !== null && !in_array($localRelay, $relays, true)) {
            array_unshift($relays, $localRelay);
        }

        return array_values(array_unique($relays));
    }

    /**
     * Store fetch attempt metadata in Redis so the sync-status endpoint can
     * report which relays were tried and when.
     */
    private function recordFetchAttempt(string $curationId, array $missingIds, array $missingCoordinates, array $relays): void
    {
        try {
            $key = sprintf('curation_sync:%s', $curationId);
            $data = json_encode([
                'attempted_at' => date('c'),
                'timestamp' => time(),
                'missing_ids' => $missingIds,
                'missing_coordinates' => $missingCoordinates,
                'relays_tried' => $relays,
            ]);
            $this->redis->setex($key, 3600, $data); // 1 hour TTL
        } catch (\Throwable) {
            // Best-effort — don't fail the fetch
        }
    }

    /** @param Event[] $persisted */
    private function publish(string $curationId, array $persisted): void
    {
        try {
            $topic = sprintf('/curation/%s/media-sync', $curationId);
            $update = new Update($topic, json_encode([
                'curationId' => $curationId,
                'count' => count($persisted),
                'eventIds' => array_map(static fn (Event $event) => $event->getId(), $persisted),
            ]), false);
            $this->hub->publish($update);
            $this->logger->info('Published curation media sync to Mercure', [
                'topic' => $topic,
                'curation_id' => $curationId,
                'persisted_count' => count($persisted),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to publish curation media sync update to Mercure', [
                'curation_id' => $curationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }

        return array_map(static function ($tag) {
            if ($tag instanceof \stdClass) {
                $tag = (array) $tag;
            }

            return is_array($tag) ? array_values($tag) : [];
        }, $tags);
    }
}

