<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\KindsEnum;
use App\Message\SyncUserEventsMessage;
use App\Message\WarmFollowsRelayPoolMessage;
use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\RelayGatewayClient;
use App\Service\Nostr\UserRelayListService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Subscription\Subscription;

/**
 * Batch-fetches all of the logged-in user's own events from their NIP-65
 * relays in a single REQ, then forwards them to the local strfry relay.
 *
 * The local relay subscription workers (articles, media, magazines) and
 * on-demand DB-first-with-relay-fallback service methods will pick up
 * the events from strfry and persist them as needed.
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
 *   5      — deletion requests (NIP-09)
 *   7      — reactions (NIP-25)
 *   1111   — comments (NIP-22)
 *   10000  — mute list (NIP-51)
 *   10001  — pin list (NIP-51)
 *   10002  — relay list (NIP-65)
 *   10003  — bookmarks (NIP-51)
 *   10015  — interests (NIP-51)
 *   10020  — media follows (NIP-68)
 *   10063  — Blossom server list (NIP-B7)
 *   30003  — bookmark sets (NIP-51)
 *   30004  — curation sets — articles (NIP-51)
 *   30005  — curation sets — videos (NIP-51)
 *   30006  — curation sets — pictures (NIP-51)
 *   30015  — interest sets (NIP-51)
 *   30023  — long-form articles (NIP-23)
 *   30024  — long-form drafts (NIP-23)
 *   30040  — publication index (NKBIP-01)
 *   34139  — playlists
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
        KindsEnum::DELETION_REQUEST->value,   // 5
        KindsEnum::REACTION->value,           // 7
        KindsEnum::COMMENTS->value,           // 1111
        KindsEnum::MUTE_LIST->value,          // 10000
        KindsEnum::PIN_LIST->value,           // 10001
        KindsEnum::RELAY_LIST->value,         // 10002
        KindsEnum::BOOKMARKS->value,          // 10003
        KindsEnum::INTERESTS->value,          // 10015
        KindsEnum::MEDIA_FOLLOWS->value,      // 10020
        KindsEnum::BLOSSOM_SERVER_LIST->value, // 10063
        KindsEnum::BOOKMARK_SETS->value,      // 30003
        KindsEnum::CURATION_SET->value,       // 30004
        KindsEnum::CURATION_VIDEOS->value,    // 30005
        KindsEnum::CURATION_PICTURES->value,  // 30006
        KindsEnum::INTEREST_SETS->value,      // 30015
        KindsEnum::LONGFORM->value,           // 30023
        KindsEnum::LONGFORM_DRAFT->value,     // 30024
        KindsEnum::PUBLICATION_INDEX->value,  // 30040
        KindsEnum::PLAYLIST->value,           // 34139
    ];

    /** Maximum number of events to request per relay to avoid overwhelming small relays. */
    private const FETCH_LIMIT = 500;

    /** Relay query timeout in seconds. Generous budget since this is async. */
    private const TIMEOUT = 30;

    public function __construct(
        private readonly RelayGatewayClient   $gatewayClient,
        private readonly NostrRelayPool       $relayPool,
        private readonly UserRelayListService $userRelayListService,
        private readonly MessageBusInterface  $messageBus,
        private readonly LoggerInterface      $logger,
        private readonly bool                 $gatewayEnabled = false,
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

        $forwarded = $this->forwardToLocalRelay($rawEvents, $pubkey);

        // Warm the follows relay pool now that the kind 3 event is in the DB.
        // This runs on async_low_priority so it won't block further processing.
        try {
            $this->messageBus->dispatch(new WarmFollowsRelayPoolMessage($pubkey));
            $this->logger->debug('SyncUserEventsHandler: dispatched follows relay pool warming', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('SyncUserEventsHandler: follows pool warm dispatch failed', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
                'error'  => $e->getMessage(),
            ]);
        }

        $this->logger->info('SyncUserEventsHandler: sync complete', [
            'pubkey'      => substr($pubkey, 0, 8) . '...',
            'received'    => count($rawEvents),
            'forwarded'   => $forwarded,
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
    // Forwarding to local relay
    // -------------------------------------------------------------------------

    /**
     * Forward raw events to the local strfry relay via WebSocket EVENT messages.
     *
     * Events pushed to strfry become available to:
     *  - Subscription workers (articles, media, magazines) that persist relevant kinds to the DB
     *  - On-demand service methods (UserProfileService, SocialEventService) that query
     *    the local relay as a fallback when the DB has no record
     *  - Any REQ query against the local relay
     *
     * This decouples the sync handler from direct DB writes and the ORM,
     * matching the relay gateway's forwardEventsToLocalRelay() pattern.
     *
     * @param array<int, array> $rawEvents Raw event arrays
     * @param string            $pubkey    Expected author pubkey (for sanity filtering)
     * @return int Number of events successfully forwarded
     */
    private function forwardToLocalRelay(array $rawEvents, string $pubkey): int
    {
        $localRelayUrl = $this->relayPool->getLocalRelay();
        if ($localRelayUrl === null) {
            $this->logger->warning('SyncUserEventsHandler: no local relay configured, cannot forward events', [
                'pubkey'      => substr($pubkey, 0, 8) . '...',
                'event_count' => count($rawEvents),
            ]);
            return 0;
        }

        // Deduplicate and filter to this user's events only
        $unique = [];
        foreach ($rawEvents as $rawEvent) {
            if (!is_array($rawEvent)) {
                continue;
            }
            $id = $rawEvent['id'] ?? null;
            if ($id === null || isset($unique[$id])) {
                continue;
            }
            if (($rawEvent['pubkey'] ?? '') !== $pubkey) {
                $this->logger->debug('SyncUserEventsHandler: skipping event from unexpected pubkey', [
                    'expected' => substr($pubkey, 0, 8),
                    'got'      => substr($rawEvent['pubkey'] ?? '', 0, 8),
                ]);
                continue;
            }
            $unique[$id] = $rawEvent;
        }

        if (empty($unique)) {
            return 0;
        }

        // Open a single WebSocket connection to strfry and push all events
        try {
            $relay = new Relay($localRelayUrl);
            $client = $relay->getClient();
            if (method_exists($client, 'setTimeout')) {
                $client->setTimeout(10);
            }
        } catch (\Throwable $e) {
            $this->logger->error('SyncUserEventsHandler: failed to connect to local relay', [
                'relay' => $localRelayUrl,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        $forwarded = 0;
        $failed = 0;
        $kindCounts = [];

        foreach ($unique as $event) {
            try {
                $client->text(json_encode(['EVENT', $event]));
                $forwarded++;

                $kind = $event['kind'] ?? 0;
                $kindCounts[$kind] = ($kindCounts[$kind] ?? 0) + 1;
            } catch (\Throwable $e) {
                $failed++;
                if ($failed === 1) {
                    $this->logger->warning('SyncUserEventsHandler: failed to forward event to local relay', [
                        'event_id' => substr($event['id'] ?? '', 0, 12),
                        'error'    => $e->getMessage(),
                    ]);
                }
                // Connection likely dead — stop trying this batch
                break;
            }
        }

        // Best-effort disconnect
        try {
            $client->disconnect();
        } catch (\Throwable) {}

        if ($forwarded > 0) {
            $this->logger->info('SyncUserEventsHandler: forwarded events to local relay', [
                'relay'       => $localRelayUrl,
                'forwarded'   => $forwarded,
                'failed'      => $failed,
                'total'       => count($unique),
                'kind_counts' => $kindCounts,
            ]);
        }

        return $forwarded;
    }
}

