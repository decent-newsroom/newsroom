<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Enum\KindsEnum;
use App\Enum\RelayPurpose;
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
        private readonly NostrRelayPool        $relayPool,
        private readonly CacheItemPoolInterface $npubCache,
        private readonly LoggerInterface       $logger,
        private readonly ?string               $nostrDefaultRelay = null,
    ) {}

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
        $relayUrls = $this->relayPool->ensureLocalRelayInList([
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
     * Fetch the latest interest list (kind 10015) for a pubkey and return the 't' tags.
     */
    public function getInterests(string $pubkey, ?array $relays = null): array
    {
        if (empty($relays)) {
            $relays = $this->userRelayListService->getRelaysForUser($pubkey, RelayPurpose::USER);
        }
        $relaySet = $this->relaySetFactory->withLocalRelay($relays);

        $events = $this->executor->fetch(
            kinds: [10015],
            filters: ['authors' => [$pubkey]],
            relaySet: $relaySet,
            handler: function ($received) use ($pubkey) {
                $this->logger->info('Getting interests for pubkey', ['pubkey' => $pubkey]);
                return $received;
            },
            pubkey: $pubkey,
        );

        if (empty($events)) {
            return [];
        }

        usort($events, fn($a, $b) => $b->created_at <=> $a->created_at);
        $latest = $events[0];

        $tTags = [];
        foreach ($latest->tags as $tag) {
            if (is_array($tag) && isset($tag[0]) && $tag[0] === 't' && isset($tag[1])) {
                $tTags[] = strtolower(trim($tag[1]));
            }
        }

        return $tTags;
    }

    // -------------------------------------------------------------------------
    // Follows
    // -------------------------------------------------------------------------

    /**
     * Fetch follow list (kind 3) for a pubkey and return the followed pubkeys.
     *
     * @throws \Exception
     */
    public function getFollows(string $pubkey, ?array $relays = null): array
    {
        $this->logger->info('Fetching follow list for pubkey', ['pubkey' => $pubkey]);

        if (empty($relays)) {
            $relays = $this->userRelayListService->getRelaysForUser($pubkey, RelayPurpose::USER);
        }
        $relaySet = $this->relaySetFactory->withLocalRelay($relays);

        $events = $this->executor->fetch(
            kinds: [KindsEnum::FOLLOWS->value],
            filters: ['authors' => [$pubkey], 'limit' => 1],
            relaySet: $relaySet,
            handler: function ($received) {
                $this->logger->info('Received follow list event', ['event' => $received]);
                return $received;
            },
            pubkey: $pubkey,
        );

        if (empty($events)) {
            $this->logger->info('No follow list found for pubkey', ['pubkey' => $pubkey]);
            return [];
        }

        usort($events, fn($a, $b) => $b->created_at <=> $a->created_at);
        $latestFollowEvent = $events[0];

        $followedPubkeys = [];
        foreach ($latestFollowEvent->tags as $tag) {
            if (is_array($tag) && isset($tag[0]) && $tag[0] === 'p' && isset($tag[1])) {
                $followedPubkeys[] = $tag[1];
            }
        }

        $this->logger->info('Follow list fetched', [
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


