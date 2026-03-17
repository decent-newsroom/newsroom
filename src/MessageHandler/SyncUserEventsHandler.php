<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\SyncUserEventsMessage;
use App\Repository\EventRepository;
use App\Service\Graph\EventIngestionListener;
use App\Service\ProfileEventIngestionService;
use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\RelayGatewayClient;
use App\Service\Nostr\UserRelayListService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Subscription\Subscription;

/**
 * Batch-fetches all of the logged-in user's own events from their NIP-65
 * relays in a single REQ, then persists new events and triggers projections.
 *
 * Runs on the async_low_priority transport so it never blocks the login
 * response. By the time this handler runs, the relay list is already warm
 * (UpdateRelayListHandler runs first) and — when the gateway is enabled —
 * the user's authenticated WebSocket connections are already open
 * (GatewayWarmConnectionsHandler runs before this handler is dispatched).
 *
 * Kinds fetched:
 *   0      — metadata (NIP-01)
 *   3      — follow list (NIP-02)
 *   7      — reactions (NIP-25)
 *   1111   — comments (NIP-22)
 *   10002  — relay list (NIP-65)
 *   10003  — bookmarks (NIP-51)
 *   10015  — interests (NIP-51)
 *   30003  — bookmark sets (NIP-51)
 *   30004  — curation sets — articles (NIP-51)
 *   30015  — interest sets (NIP-51)
 *   30023  — long-form articles (NIP-23)
 *   30024  — long-form drafts (NIP-23)
 *   30040  — publication index (NKBIP-01)
 */
#[AsMessageHandler]
class SyncUserEventsHandler
{
    /**
     * All Nostr event kinds to fetch for the logged-in user.
     * This list is intentionally broad — profile + social graph + content + lists.
     */
    private const SYNC_KINDS = [
        KindsEnum::METADATA->value,          // 0
        KindsEnum::FOLLOWS->value,            // 3
        KindsEnum::REACTION->value,           // 7
        KindsEnum::COMMENTS->value,           // 1111
        KindsEnum::RELAY_LIST->value,         // 10002
        KindsEnum::BOOKMARKS->value,          // 10003
        KindsEnum::INTERESTS->value,          // 10015
        KindsEnum::MEDIA_FOLLOWS->value,      // 10020
        KindsEnum::BOOKMARK_SETS->value,      // 30003
        KindsEnum::CURATION_SET->value,       // 30004
        KindsEnum::CURATION_VIDEOS->value,    // 30005
        KindsEnum::CURATION_PICTURES->value,  // 30006
        KindsEnum::LONGFORM->value,           // 30023
        KindsEnum::LONGFORM_DRAFT->value,     // 30024
        KindsEnum::PUBLICATION_INDEX->value,  // 30040
        30015,                                // interest sets (NIP-51, no enum case yet)
    ];

    /** Maximum number of events to request per relay to avoid overwhelming small relays. */
    private const FETCH_LIMIT = 500;

    /** Relay query timeout in seconds. Generous budget since this is async. */
    private const TIMEOUT = 30;

    public function __construct(
        private readonly RelayGatewayClient           $gatewayClient,
        private readonly NostrRelayPool               $relayPool,
        private readonly UserRelayListService         $userRelayListService,
        private readonly EventRepository              $eventRepository,
        private readonly EntityManagerInterface       $entityManager,
        private readonly ProfileEventIngestionService $profileIngestionService,
        private readonly LoggerInterface              $logger,
        private readonly EventIngestionListener       $eventIngestionListener,
        private readonly bool                         $gatewayEnabled = false,
    ) {}

    public function __invoke(SyncUserEventsMessage $message): void
    {
        $pubkey   = $message->pubkeyHex;
        $relayUrls = $message->relayUrls;
        $since    = $message->since;

        // Re-resolve relays if the message carries an empty list (shouldn't happen,
        // but be defensive against race conditions with the relay list warm step).
        if (empty($relayUrls)) {
            $relayUrls = $this->userRelayListService->getRelaysForFetching($pubkey);
        }

        if (empty($relayUrls)) {
            $this->logger->warning('SyncUserEventsHandler: no relays available, skipping', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
            ]);
            return;
        }

        $this->logger->info('SyncUserEventsHandler: starting batch event sync', [
            'pubkey'     => substr($pubkey, 0, 8) . '...',
            'relay_count' => count($relayUrls),
            'kinds'      => self::SYNC_KINDS,
            'since'      => $since ?: 'all-time',
        ]);

        $filter = [
            'kinds'   => self::SYNC_KINDS,
            'authors' => [$pubkey],
            'limit'   => self::FETCH_LIMIT,
        ];

        if ($since > 0) {
            $filter['since'] = $since;
        }

        $rawEvents = $this->fetchEvents($relayUrls, $filter, $pubkey);

        if (empty($rawEvents)) {
            $this->logger->info('SyncUserEventsHandler: no events returned', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
            ]);
            return;
        }

        $this->logger->info('SyncUserEventsHandler: received events from relays', [
            'pubkey'      => substr($pubkey, 0, 8) . '...',
            'event_count' => count($rawEvents),
        ]);

        $persisted = $this->persistEvents($rawEvents, $pubkey);

        $this->logger->info('SyncUserEventsHandler: sync complete', [
            'pubkey'      => substr($pubkey, 0, 8) . '...',
            'received'    => count($rawEvents),
            'persisted'   => $persisted,
        ]);
    }

    // -------------------------------------------------------------------------
    // Fetching
    // -------------------------------------------------------------------------

    /**
     * Fetch events via the relay gateway (when enabled) or directly via
     * NostrRelayPool::sendToRelays() as fallback.
     *
     * Returns a flat array of raw event arrays (as returned by json_decode of
     * the relay response — same format as RelayGatewayClient::query()).
     *
     * @return array<int, array> Raw event arrays
     */
    private function fetchEvents(array $relayUrls, array $filter, string $pubkey): array
    {
        if ($this->gatewayEnabled) {
            return $this->fetchViaGateway($relayUrls, $filter, $pubkey);
        }

        return $this->fetchDirect($relayUrls, $filter);
    }

    /**
     * Fetch via the relay gateway — uses the user's authenticated connection
     * so AUTH-gated relays return results without an additional challenge.
     */
    private function fetchViaGateway(array $relayUrls, array $filter, string $pubkey): array
    {
        try {
            $result = $this->gatewayClient->query($relayUrls, $filter, $pubkey, self::TIMEOUT);

            if (!empty($result['errors'])) {
                $this->logger->warning('SyncUserEventsHandler: gateway reported relay errors', [
                    'pubkey' => substr($pubkey, 0, 8) . '...',
                    'errors' => $result['errors'],
                ]);
            }

            return $result['events'] ?? [];

        } catch (\Throwable $e) {
            $this->logger->error('SyncUserEventsHandler: gateway fetch failed', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
                'error'  => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Fetch directly via WebSocket (gateway disabled path).
     * Uses NostrRelayPool::sendToRelays() with TweakedRequest so AUTH,
     * PING/PONG, and CLOSE are handled correctly.
     *
     * @return array<int, array> Raw event arrays extracted from RelayResponseEvent objects
     */
    private function fetchDirect(array $relayUrls, array $filter): array
    {
        $this->logger->info('SyncUserEventsHandler: gateway disabled, fetching via direct WebSocket', [
            'relay_count' => count($relayUrls),
        ]);

        try {
            $filterKinds   = $filter['kinds'] ?? [];
            $filterAuthors = $filter['authors'] ?? [];
            $filterSince   = $filter['since'] ?? null;
            $filterLimit   = $filter['limit'] ?? self::FETCH_LIMIT;

            $responses = $this->relayPool->sendToRelays(
                $relayUrls,
                function () use ($filterKinds, $filterAuthors, $filterSince, $filterLimit) {
                    $subscription = new Subscription();
                    $subscriptionId = $subscription->setId();

                    $f = new Filter();
                    $f->setKinds($filterKinds);
                    $f->setAuthors($filterAuthors);
                    $f->setLimit($filterLimit);
                    if ($filterSince !== null) {
                        $f->setSince($filterSince);
                    }

                    return new RequestMessage($subscriptionId, [$f]);
                },
                self::TIMEOUT,
            );

            // Flatten RelayResponseEvent objects → raw event arrays, deduplicating by ID
            $events  = [];
            $seenIds = [];
            foreach ($responses as $relayResponses) {
                foreach ($relayResponses as $item) {
                    if (!is_object($item) || ($item->type ?? '') !== 'EVENT' || !isset($item->event)) {
                        continue;
                    }
                    $event = $item->event;
                    $id    = $event->id ?? null;
                    if ($id && !isset($seenIds[$id])) {
                        $seenIds[$id] = true;
                        // Convert stdClass → array for uniform handling in persistEvents()
                        $events[] = json_decode(json_encode($event), true);
                    }
                }
            }

            return $events;

        } catch (\Throwable $e) {
            $this->logger->error('SyncUserEventsHandler: direct WebSocket fetch failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    /**
     * Persist raw events that don't already exist in the database.
     * Triggers profile projection updates for profile-related kinds.
     *
     * @param array<int, array> $rawEvents
     * @return int Number of newly persisted events
     */
    private function persistEvents(array $rawEvents, string $pubkey): int
    {
        $persisted = 0;
        $batchSize = 50;
        $batch     = 0;

        // Collect all event IDs in this batch to skip a SELECT per event
        $incomingIds = array_filter(array_column($rawEvents, 'id'));
        $existingIds = $this->eventRepository->findExistingIds($incomingIds);

        foreach ($rawEvents as $rawEvent) {
            if (!is_array($rawEvent)) {
                continue;
            }

            $eventId = $rawEvent['id'] ?? null;
            if (!$eventId || isset($existingIds[$eventId])) {
                continue; // Already in DB — skip
            }

            // Validate the event belongs to this user (sanity check)
            if (($rawEvent['pubkey'] ?? '') !== $pubkey) {
                $this->logger->debug('SyncUserEventsHandler: skipping event from unexpected pubkey', [
                    'expected' => substr($pubkey, 0, 8),
                    'got'      => substr($rawEvent['pubkey'] ?? '', 0, 8),
                ]);
                continue;
            }

            try {
                $entity = $this->buildEntity($rawEvent);
                $this->entityManager->persist($entity);

                // Update graph layer tables (uses DBAL, not ORM — safe before flush)
                try {
                    $this->eventIngestionListener->processEvent($entity);
                } catch (\Throwable) {}

                $persisted++;
                $batch++;

                // Flush in batches to avoid large memory spikes
                if ($batch >= $batchSize) {
                    $this->entityManager->flush();
                    $batch = 0;
                    $this->triggerProjections($rawEvents, $pubkey);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('SyncUserEventsHandler: failed to persist event', [
                    'event_id' => $eventId,
                    'kind'     => $rawEvent['kind'] ?? '?',
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        // Final flush
        if ($batch > 0) {
            $this->entityManager->flush();
        }

        // Trigger profile projections once for all persisted events
        if ($persisted > 0) {
            $this->triggerProjections($rawEvents, $pubkey);
        }

        return $persisted;
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
     * Notify ProfileEventIngestionService about any profile-related events
     * (kind 0, kind 10002) so projection updates fire asynchronously.
     *
     * @param array<int, array> $rawEvents
     */
    private function triggerProjections(array $rawEvents, string $pubkey): void
    {
        // Build lightweight Event stubs — ProfileEventIngestionService only
        // needs getKind() and getPubkey() to decide whether to dispatch.
        $profileKinds = [
            KindsEnum::METADATA->value,
            KindsEnum::RELAY_LIST->value,
        ];

        $profileEvents = [];
        foreach ($rawEvents as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            if (in_array((int) ($raw['kind'] ?? -1), $profileKinds, true)) {
                // Re-use the entity builder — cheap since these are rare kinds
                try {
                    $profileEvents[] = $this->buildEntity($raw);
                } catch (\Throwable) {}
            }
        }

        if (!empty($profileEvents)) {
            $this->profileIngestionService->handleBatchEventIngestion($profileEvents);
        }
    }
}

