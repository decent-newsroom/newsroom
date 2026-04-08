<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Entity\Event;
use App\Enum\KindBundles;
use App\Enum\KindsEnum;
use App\Enum\RelayPurpose;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * User profile operations: metadata, relay lists, interests, follows, bookmarks.
 *
 * Extracted from NostrClient.
 */
class UserProfileService
{
    public function __construct(
        private readonly NostrRequestExecutor  $executor,
        private readonly RelaySetFactory       $relaySetFactory,
        private readonly UserRelayListService  $userRelayListService,
        private readonly RelayRegistry         $relayRegistry,
        private readonly EventRepository       $eventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheItemPoolInterface $npubCache,
        private readonly LoggerInterface       $logger,
        private readonly ?string               $nostrDefaultRelay = null,
    ) {}

    // -------------------------------------------------------------------------
    // Combined User Context Fetch (Phase 1)
    // -------------------------------------------------------------------------

    /**
     * Fetch all user context kinds in a single REQ and persist each result.
     *
     * Replaces individual relay calls for metadata, follows, interests,
     * media follows, relay list, etc. — reducing 3–5 relay round-trips to 1.
     *
     * @param string      $pubkey Hex pubkey
     * @param string[]|null $relays Override relay list (null = auto-discover)
     * @return array<int, object> kind => latest event (newest per kind)
     */
    public function fetchUserContext(string $pubkey, ?array $relays = null): array
    {
        $this->logger->info('UserProfileService: fetching combined user context', [
            'pubkey' => substr($pubkey, 0, 8) . '...',
            'kinds'  => KindBundles::USER_CONTEXT,
        ]);

        if (empty($relays)) {
            $relays = $this->userRelayListService->getRelaysForUser($pubkey, RelayPurpose::USER);
        }
        $relaySet = $this->relaySetFactory->withLocalRelay($relays);

        $events = $this->executor->fetch(
            kinds: KindBundles::USER_CONTEXT,
            filters: ['authors' => [$pubkey]],
            relaySet: $relaySet,
            handler: fn($received) => $received,
            pubkey: $pubkey,
        );

        if (empty($events)) {
            $this->logger->info('UserProfileService: no user context events found', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
            ]);
            return [];
        }

        // Group by kind, take the latest event for each, and persist
        $latestByKind = KindBundles::latestByKind($events);

        foreach ($latestByKind as $kind => $event) {
            $this->persistEvent($event);
        }

        $this->logger->info('UserProfileService: user context fetched and persisted', [
            'pubkey'      => substr($pubkey, 0, 8) . '...',
            'kinds_found' => array_keys($latestByKind),
            'total_events' => count($events),
        ]);

        return $latestByKind;
    }

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    /**
     * Fetch kind-0 metadata for a single pubkey.
     *
     * @throws \Exception when no metadata is found
     */
    public function getMetadata(string $pubkey): \stdClass
    {
        $relayUrls = $this->relayRegistry->ensureLocalRelayInList([
            'wss://theforest.nostr1.com',
            'wss://nostr.land',
            'wss://relay.primal.net',
            'wss://purplepag.es',
        ]);
        $relaySet = $this->relaySetFactory->fromUrls($relayUrls);

        $this->logger->debug('Getting metadata for pubkey', ['pubkey' => $pubkey]);

        $events = $this->executor->fetch(
            kinds: [KindsEnum::METADATA],
            filters: ['authors' => [$pubkey]],
            relaySet: $relaySet,
            handler: function ($received) {
                $this->logger->debug('Received metadata event for pubkey', ['item' => $received]);
                return $received;
            }
        );

        if (empty($events)) {
            throw new \Exception('No metadata found for pubkey: ' . $pubkey);
        }

        usort($events, fn($a, $b) => $b->created_at <=> $a->created_at);
        return $events[0];
    }

    /**
     * Batch-fetch kind-0 metadata for multiple pubkeys.
     *
     * Queries the local relay first; missing pubkeys are retried on public
     * content relays in chunks of 50.
     *
     * @param  string[] $pubkeys  Hex-encoded pubkeys
     * @return array<string, object>  pubkey => metadata event
     */
    public function getBatchMetadata(array $pubkeys, bool $fallback = true): array
    {
        $pubkeys = array_values(array_unique(
            array_filter($pubkeys, fn($p) => is_string($p) && strlen($p) === 64)
        ));

        if (empty($pubkeys)) {
            return [];
        }

        $this->logger->info('Batch fetching metadata', ['count' => count($pubkeys), 'fallback' => $fallback]);

        $results = [];
        $missing = $pubkeys;

        $process = function (array $events) use (&$results, &$missing) {
            foreach ($events as $event) {
                $pk = $event->pubkey ?? null;
                if (!$pk) {
                    continue;
                }
                if (!isset($results[$pk]) || ($event->created_at ?? 0) > ($results[$pk]->created_at ?? 0)) {
                    $results[$pk] = $event;
                }
            }
            $missing = array_values(array_diff($missing, array_keys($results)));
        };

        // First pass: local relay
        try {
            if ($this->nostrDefaultRelay) {
                $relaySet = $this->relaySetFactory->fromUrls([$this->nostrDefaultRelay]);
                $events   = $this->executor->fetch(
                    kinds: [KindsEnum::METADATA->value],
                    filters: ['authors' => $missing, 'limit' => count($missing)],
                    relaySet: $relaySet,
                );
                $process($events);
                $this->logger->info('Local relay metadata fetch complete', [
                    'found'     => count($results),
                    'remaining' => count($missing),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Local relay batch metadata fetch failed', ['error' => $e->getMessage()]);
        }

        // Second pass: content relays in chunks
        if ($fallback && !empty($missing)) {
            $relaySet = $this->relaySetFactory->forPurpose(RelayPurpose::CONTENT);
            foreach (array_chunk($missing, 50) as $chunk) {
                if (empty($chunk)) {
                    continue;
                }
                try {
                    $events = $this->executor->fetch(
                        kinds: [KindsEnum::METADATA->value],
                        filters: ['authors' => $chunk, 'limit' => count($chunk)],
                        relaySet: $relaySet,
                    );
                    $process($events);
                    $this->logger->info('Fallback metadata chunk fetched', [
                        'chunk_size'  => count($chunk),
                        'found_total' => count($results),
                        'remaining'   => count($missing),
                    ]);
                    if (empty($missing)) {
                        break;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Fallback metadata chunk failed', [
                        'error'      => $e->getMessage(),
                        'chunk_size' => count($chunk),
                    ]);
                }
            }
        }

        // Cache individual events
        foreach ($results as $pubkey => $event) {
            try {
                $cacheKey   = 'pubkey_meta_' . $pubkey;
                $cachedItem = $this->npubCache->getItem($cacheKey);
                if (!$cachedItem->isHit() || ($event->created_at ?? 0) > ($cachedItem->get()->created_at ?? 0)) {
                    $cachedItem->set($event);
                    $cachedItem->expiresAfter(84000); // ~24 h
                    $this->npubCache->save($cachedItem);
                }
            } catch (\Throwable) {
                // Non-critical
            }
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Relays
    // -------------------------------------------------------------------------

    /**
     * Get relay list for an npub/pubkey (delegates to UserRelayListService).
     */
    public function getRelays(string $npub): array
    {
        return $this->userRelayListService->getRelays($npub);
    }

    // -------------------------------------------------------------------------
    // Interests
    // -------------------------------------------------------------------------

    /**
     * Return the 't' tags from the user's latest kind-10015 interest list.
     *
     * Strategy: local DB only (populated from local strfry relay).
     *
     * SyncUserEventsHandler fetches user events from their NIP-65 relays on
     * login and forwards them to the local strfry relay. Subscription workers
     * then persist them to the DB. If the interests event is not in the DB,
     * the user probably hasn't published one yet.
     */
    public function getInterests(string $pubkey): array
    {
        $dbEvent = $this->eventRepository->findLatestByPubkeyAndKind($pubkey, KindsEnum::INTERESTS->value);

        if ($dbEvent !== null) {
            $this->logger->debug('UserProfileService: interests loaded from DB', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
            ]);
            return $this->extractTTags($dbEvent->getTags());
        }

        $this->logger->debug('UserProfileService: no interests event found in local DB', [
            'pubkey' => substr($pubkey, 0, 8) . '...',
        ]);

        return [];
    }


    /**
     * Return interest sets (kind 30015) relevant to a user.
     *
     * Sources (merged, deduplicated by coordinate):
     *   1. Sets referenced via "a" tags in the user's kind-10015 interests event
     *      (these can be authored by anyone).
     *   2. Free-floating sets authored by the user themselves (own pubkey,
     *      kind 30015) — even if not referenced in the interests list.
     *
     * @return array<int, array{title: string, tags: string[], pubkey: string, dTag: string}>
     */
    public function getInterestSets(string $pubkey): array
    {
        $sets = [];
        $seen = []; // coordinate => true, for deduplication

        // ── 1. Referenced sets from kind 10015 "a" tags ──
        $dbEvent = $this->eventRepository->findLatestByPubkeyAndKind($pubkey, KindsEnum::INTERESTS->value);

        if ($dbEvent !== null) {
            $coordinates = $this->extractInterestSetCoordinates($dbEvent->getTags());

            foreach ($coordinates as $coord) {
                $coordKey = $coord['pubkey'] . ':' . $coord['dTag'];
                if (isset($seen[$coordKey])) {
                    continue;
                }

                $setEvent = $this->eventRepository->findByNaddr(
                    KindsEnum::INTEREST_SETS->value,
                    $coord['pubkey'],
                    $coord['dTag'],
                );

                if ($setEvent === null) {
                    continue;
                }

                $title = $setEvent->getTitle() ?? $coord['dTag'];
                $tags = $this->extractTTags($setEvent->getTags());

                if (empty($tags)) {
                    continue;
                }

                $seen[$coordKey] = true;
                $sets[] = [
                    'title'  => $title,
                    'tags'   => $tags,
                    'pubkey' => $coord['pubkey'],
                    'dTag'   => $coord['dTag'],
                ];
            }
        }

        // ── 2. User's own free-floating kind 30015 sets ──
        $ownSets = $this->eventRepository->findAllByPubkeyAndKind($pubkey, KindsEnum::INTEREST_SETS->value);

        foreach ($ownSets as $setEvent) {
            $dTag = $setEvent->getDTag() ?? '';
            $coordKey = $pubkey . ':' . $dTag;
            if (isset($seen[$coordKey])) {
                continue;
            }

            $title = $setEvent->getTitle() ?? $dTag;
            $tags = $this->extractTTags($setEvent->getTags());

            if (empty($tags)) {
                continue;
            }

            $seen[$coordKey] = true;
            $sets[] = [
                'title'  => $title,
                'tags'   => $tags,
                'pubkey' => $pubkey,
                'dTag'   => $dTag,
            ];
        }

        $this->logger->debug('UserProfileService: resolved interest sets', [
            'pubkey'   => substr($pubkey, 0, 8) . '...',
            'total'    => count($sets),
        ]);

        return $sets;
    }

    /**
     * Extract kind:30015 coordinate references from a tags array.
     *
     * @return array<int, array{pubkey: string, dTag: string}>
     */
    private function extractInterestSetCoordinates(array $tags): array
    {
        $coords = [];
        foreach ($tags as $tag) {
            if ($tag instanceof \stdClass) {
                $tag = (array) $tag;
            }
            if (!is_array($tag) || ($tag[0] ?? '') !== 'a' || !isset($tag[1])) {
                continue;
            }
            $parts = explode(':', (string) $tag[1], 3);
            if (count($parts) !== 3) {
                continue;
            }
            // Only match kind 30015 interest sets
            if ((int) $parts[0] !== KindsEnum::INTEREST_SETS->value) {
                continue;
            }
            $coords[] = [
                'pubkey' => $parts[1],
                'dTag'   => $parts[2],
            ];
        }

        return $coords;
    }

    /**
     * Persist a kind-10015 event to the local DB.
     * Public alias kept for ForumController compatibility.
     */
    public function persistInterestEvent(object $event): void
    {
        $this->persistEvent($event);
    }

    /**
     * Persist any Nostr event to the local DB (public entry point).
     * No-ops silently if the event already exists or data is invalid.
     */
    public function persistUserEvent(object $event): void
    {
        $this->persistEvent($event);
    }

    /**
     * Persist any Nostr event object (stdClass from relay) to the local DB.
     * No-ops silently if the event already exists or the data is invalid.
     */
    private function persistEvent(object $event): void
    {
        $id = $event->id ?? null;
        if (!$id) {
            return;
        }

        // Skip if already stored
        if ($this->eventRepository->find($id) !== null) {
            return;
        }

        try {
            $entity = new Event();
            $entity->setId($id);
            $entity->setKind((int) ($event->kind ?? KindsEnum::INTERESTS->value));
            $entity->setPubkey($event->pubkey ?? '');
            $entity->setContent($event->content ?? '');
            $entity->setCreatedAt((int) ($event->created_at ?? time()));
            $entity->setTags(is_array($event->tags) ? $event->tags : (array) ($event->tags ?? []));
            $entity->setSig($event->sig ?? '');
            $entity->extractAndSetDTag();

            $this->entityManager->persist($entity);
            $this->entityManager->flush();

            $this->logger->debug('UserProfileService: persisted event to DB', [
                'event_id' => substr($id, 0, 16) . '...',
                'kind'     => $event->kind ?? '?',
                'pubkey'   => substr($event->pubkey ?? '', 0, 8) . '...',
            ]);
        } catch (\Throwable $e) {
            // Non-fatal — DB is a cache; relay remains source of truth
            $this->logger->warning('UserProfileService: failed to persist event', [
                'event_id' => $id,
                'kind'     => $event->kind ?? '?',
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extract lowercase 't' tag values from a tags array.
     *
     * Accepts both the array-of-arrays format from a relay response (stdClass
     * tags converted to arrays) and the raw arrays stored in the Event entity.
     *
     * @param array $tags
     * @return string[]
     */
    private function extractTTags(array $tags): array
    {
        $tTags = [];
        foreach ($tags as $tag) {
            // Tags from the DB entity are plain arrays: ['t', 'bitcoin', ...]
            // Tags from relay responses may arrive as arrays or stdClass objects
            if ($tag instanceof \stdClass) {
                $tag = (array) $tag;
            }
            if (is_array($tag) && ($tag[0] ?? '') === 't' && isset($tag[1])) {
                $tTags[] = strtolower(trim((string) $tag[1]));
            }
        }

        return $tTags;
    }

    // -------------------------------------------------------------------------
    // Follows
    // -------------------------------------------------------------------------

    /**
     * Return the pubkeys from the user's latest kind-3 follow list.
     *
     * Strategy: DB-first with relay fallback, same as getInterests().
     *
     * 1. Check the local database — SyncUserEventsHandler populates this on login.
     * 2. Fall back to a live relay query only on a DB miss, then write-through.
     *
     * @return string[] Hex pubkeys of followed accounts
     * @throws \Exception
     */
    public function getFollows(string $pubkey, ?array $relays = null): array
    {
        $this->logger->info('Fetching follow list for pubkey', ['pubkey' => $pubkey]);

        // ── 1. DB lookup ──────────────────────────────────────────────────────
        $dbEvent = $this->eventRepository->findLatestByPubkeyAndKind($pubkey, KindsEnum::FOLLOWS->value);

        if ($dbEvent !== null) {
            $this->logger->debug('UserProfileService: follows loaded from DB', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
            ]);
            return $this->extractPTags($dbEvent->getTags());
        }

        // ── 2. Relay fallback (combined user context fetch) ────────────────
        $this->logger->debug('UserProfileService: follows not in DB, querying relay via combined fetch', [
            'pubkey' => substr($pubkey, 0, 8) . '...',
        ]);

        $latestByKind = $this->fetchUserContext($pubkey, $relays);

        $followEvent = $latestByKind[KindsEnum::FOLLOWS->value] ?? null;
        if ($followEvent === null) {
            $this->logger->info('No follow list found for pubkey', ['pubkey' => $pubkey]);
            return [];
        }

        $followedPubkeys = $this->extractPTags((array) ($followEvent->tags ?? []));

        $this->logger->info('Follow list fetched', [
            'pubkey'        => $pubkey,
            'follows_count' => count($followedPubkeys),
        ]);

        return $followedPubkeys;
    }

    /**
     * Extract hex pubkeys from 'p' tags.
     * Handles both plain arrays (from DB) and stdClass (from relay response).
     *
     * @return string[]
     */
    private function extractPTags(array $tags): array
    {
        $pubkeys = [];
        foreach ($tags as $tag) {
            if ($tag instanceof \stdClass) {
                $tag = (array) $tag;
            }
            if (is_array($tag) && ($tag[0] ?? '') === 'p' && isset($tag[1])) {
                $pubkeys[] = $tag[1];
            }
        }
        return $pubkeys;
    }

    // -------------------------------------------------------------------------
    // Media Follows (kind 10020, NIP-68)
    // -------------------------------------------------------------------------

    /**
     * Return the pubkeys from the user's latest kind-10020 media follow list.
     *
     * Strategy: DB-first with relay fallback, same as getFollows().
     *
     * @return string[] Hex pubkeys of media-followed accounts
     */
    public function getMediaFollows(string $pubkey, ?array $relays = null): array
    {
        $this->logger->info('Fetching media follow list for pubkey', ['pubkey' => $pubkey]);

        // ── 1. DB lookup ──────────────────────────────────────────────────────
        $dbEvent = $this->eventRepository->findLatestByPubkeyAndKind($pubkey, KindsEnum::MEDIA_FOLLOWS->value);

        if ($dbEvent !== null) {
            $this->logger->debug('UserProfileService: media follows loaded from DB', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
            ]);
            return $this->extractPTags($dbEvent->getTags());
        }

        // ── 2. Relay fallback (combined user context fetch) ────────────────
        $this->logger->debug('UserProfileService: media follows not in DB, querying relay via combined fetch', [
            'pubkey' => substr($pubkey, 0, 8) . '...',
        ]);

        $latestByKind = $this->fetchUserContext($pubkey, $relays);

        $mediaFollowEvent = $latestByKind[KindsEnum::MEDIA_FOLLOWS->value] ?? null;
        if ($mediaFollowEvent === null) {
            $this->logger->info('No media follow list found for pubkey', ['pubkey' => $pubkey]);
            return [];
        }

        $followedPubkeys = $this->extractPTags((array) ($mediaFollowEvent->tags ?? []));

        $this->logger->info('Media follow list fetched', [
            'pubkey'        => $pubkey,
            'follows_count' => count($followedPubkeys),
        ]);

        return $followedPubkeys;
    }

    // -------------------------------------------------------------------------
    // Bookmarks
    // -------------------------------------------------------------------------

    /**
     * Fetch bookmark events (kinds 10003, 30003–30006) for a pubkey from relays.
     */
    public function getBookmarks(string $pubkey): array
    {
        $request = $this->executor->buildRequest(
            kinds: [
                KindsEnum::BOOKMARKS->value,
                KindsEnum::BOOKMARK_SETS->value,
                KindsEnum::CURATION_SET->value,
                KindsEnum::CURATION_VIDEOS->value,
                KindsEnum::CURATION_PICTURES->value,
            ],
            filters: ['authors' => [$pubkey]],
        );

        return $this->executor->process(
            $this->executor->execute($request),
            function ($received) use ($pubkey) {
                $this->logger->info('Getting bookmarks for pubkey', ['pubkey' => $pubkey]);
                return $received;
            }
        );
    }
}


