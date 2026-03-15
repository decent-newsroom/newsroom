<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Enum\RelayPurpose;
use App\Repository\EventRepository;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Subscription\Subscription;

/**
 * Stale-while-revalidate relay list resolution with DB write-through.
 *
 * Resolution order:
 *   1. Redis/PSR-6 cache (hot, < TTL)
 *   2. Database (Event table, kind 10002) — durable stale copy
 *   3. Network fetch from profile relays (NIP-65)
 *   4. Fallback relays from RelayRegistry
 *
 * On successful network fetch the kind 10002 event is persisted to the
 * Event table (only if `created_at` is newer). This guarantees that relay
 * lists — notoriously unreliable to find on the network — survive cache
 * evictions, Redis restarts, and cold starts.
 *
 * Replaces both AuthorRelayService and the fragmented relay resolution
 * scattered across NostrClient (getNpubRelays, getRelaysFromDatabase,
 * getRelaysFromAuthorService, getTopReputableRelaysForAuthor).
 */
class UserRelayListService
{
    private const CACHE_TTL = 3600;       // 1 hour hot cache
    private const CACHE_PREFIX = 'user_relay_list_';

    public function __construct(
        private readonly NostrRelayPool $relayPool,
        private readonly RelayRegistry $relayRegistry,
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $em,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Get the structured relay list for a pubkey.
     *
     * @return array{read: string[], write: string[], all: string[], created_at: ?int}
     */
    public function getRelayList(string $pubkeyOrNpub): array
    {
        $hex = $this->toHex($pubkeyOrNpub);

        // 1. Hot cache
        $cached = $this->fromCache($hex);
        if ($cached !== null) {
            return $cached;
        }

        // 2. Database (durable stale copy)
        $dbResult = $this->fromDatabase($hex);
        if ($dbResult !== null) {
            // Warm cache from DB, then trigger async revalidation (best-effort)
            $this->writeCache($hex, $dbResult);
            return $dbResult;
        }

        // 3. Network fetch (blocking)
        $networkResult = $this->fromNetwork($hex);
        if ($networkResult !== null) {
            return $networkResult;
        }

        // 4. Fallback
        $fallbacks = $this->relayRegistry->getFallbackRelays();
        return [
            'read' => $fallbacks,
            'write' => $fallbacks,
            'all' => $fallbacks,
            'created_at' => null,
        ];
    }

    /**
     * Get a flat array of relay URLs for a pubkey (backward-compatible).
     *
     * @return string[]
     */
    public function getRelays(string $pubkeyOrNpub): array
    {
        $list = $this->getRelayList($pubkeyOrNpub);
        return array_values(array_unique(array_merge(
            $list['read'] ?? [],
            $list['write'] ?? [],
            $list['all'] ?? [],
        )));
    }

    /**
     * Get relays for a specific purpose, merging user relays with registry defaults.
     *
     * @return string[]
     */
    public function getRelaysForUser(string $pubkeyOrNpub, RelayPurpose $purpose, int $limit = 5): array
    {
        $hex = $this->toHex($pubkeyOrNpub);
        $userList = $this->getRelayList($hex);

        $relays = [];

        // Start with local relay
        $local = $this->relayRegistry->getLocalRelay();
        if ($local) {
            $relays[] = $local;
        }

        // Add user relays based on purpose
        $userRelays = match ($purpose) {
            RelayPurpose::CONTENT => $userList['read'] ?? $userList['all'] ?? [],
            RelayPurpose::PROFILE => $userList['all'] ?? [],
            default => $userList['all'] ?? [],
        };
        foreach ($userRelays as $relay) {
            if (!in_array($relay, $relays, true) && $this->isValidRelay($relay)) {
                $relays[] = $relay;
            }
        }

        // Fill with registry defaults for this purpose
        foreach ($this->relayRegistry->getForPurpose($purpose) as $relay) {
            if (!in_array($relay, $relays, true)) {
                $relays[] = $relay;
            }
        }

        return array_slice($relays, 0, $limit);
    }

    /**
     * Get relays suitable for fetching content by this author.
     * Shortcut for getRelaysForUser() with CONTENT purpose.
     *
     * @return string[]
     */
    public function getRelaysForFetching(string $pubkeyOrNpub, int $limit = 5): array
    {
        return $this->getRelaysForUser($pubkeyOrNpub, RelayPurpose::CONTENT, $limit);
    }

    /**
     * Get relays suitable for publishing on behalf of this author.
     * Non-blocking: returns fallbacks immediately on cache miss.
     *
     * @return string[]
     */
    public function getRelaysForPublishing(string $pubkeyOrNpub, int $limit = 5): array
    {
        $hex = $this->toHex($pubkeyOrNpub);

        // Try cache only — publishing must be fast (non-blocking)
        $cached = $this->fromCache($hex);
        $writeRelays = $cached['write'] ?? $cached['all'] ?? null;

        $relays = [];

        $local = $this->relayRegistry->getLocalRelay();
        if ($local) {
            $relays[] = $local;
        }

        if ($writeRelays) {
            foreach ($writeRelays as $relay) {
                if (!in_array($relay, $relays, true) && $this->isValidRelay($relay)) {
                    $relays[] = $relay;
                }
            }
        }

        // Fill with project relay fallbacks
        foreach ($this->relayRegistry->getFallbackRelays() as $relay) {
            if (!in_array($relay, $relays, true)) {
                $relays[] = $relay;
            }
        }

        return array_slice($relays, 0, $limit);
    }

    /**
     * Get the "top reputable" relays for an author — relays that are both
     * in the user's declared list AND in the registry's content relays.
     * Falls back to content relays if no overlap or no relay list found.
     *
     * Drop-in replacement for NostrClient::getTopReputableRelaysForAuthor().
     *
     * @return string[]
     */
    public function getTopRelaysForAuthor(string $pubkeyOrNpub, int $limit = 3): array
    {
        $allRelays = $this->getRelays($pubkeyOrNpub);
        $contentRelays = $this->relayRegistry->getContentRelays();

        if (empty($allRelays)) {
            return array_slice($contentRelays, 0, $limit);
        }

        // Prefer relays that are in both the author's list and content relays
        $overlap = [];
        foreach ($contentRelays as $relay) {
            if (in_array($relay, $allRelays, true) && count($overlap) < $limit) {
                $overlap[] = $relay;
            }
        }

        if (!empty($overlap)) {
            return $overlap;
        }

        // No overlap — return the author's relays filtered to valid wss:// only
        $filtered = array_filter($allRelays, fn(string $r) => $this->isValidRelay($r));
        return array_slice(array_values($filtered), 0, $limit);
    }

    /**
     * Get fallback relays (local + project). Convenience alias for RelayRegistry.
     * @return string[]
     */
    public function getFallbackRelays(): array
    {
        return $this->relayRegistry->getFallbackRelays();
    }

    /**
     * Get the structured relay list for a pubkey.
     * Backward-compatible alias for getRelayList() — matches the old
     * AuthorRelayService::getAuthorRelays() signature.
     *
     * @return array{read: string[], write: string[], all: string[], created_at: ?int}
     */
    public function getAuthorRelays(string $pubkey, bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            $this->revalidate($pubkey);
        }
        return $this->getRelayList($pubkey);
    }

    /**
     * Force a network revalidation and persist the result.
     * Called by the async UpdateRelayListMessage handler on login.
     */
    public function revalidate(string $pubkeyOrNpub): void
    {
        $hex = $this->toHex($pubkeyOrNpub);
        $this->fromNetwork($hex);
    }

    /**
     * Invalidate all caches for a pubkey.
     */
    public function invalidate(string $pubkeyOrNpub): void
    {
        $hex = $this->toHex($pubkeyOrNpub);
        try {
            $this->cache->deleteItem(self::CACHE_PREFIX . $hex);
        } catch (\Exception $e) {
            $this->logger->warning('UserRelayListService: cache invalidation failed', ['error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // Resolution layers
    // ------------------------------------------------------------------

    private function fromCache(string $hex): ?array
    {
        try {
            $item = $this->cache->getItem(self::CACHE_PREFIX . $hex);
            if ($item->isHit()) {
                $this->logger->debug('UserRelayListService: cache hit', ['pubkey' => substr($hex, 0, 8)]);
                return $item->get();
            }
        } catch (\Exception $e) {
            $this->logger->warning('UserRelayListService: cache read error', ['error' => $e->getMessage()]);
        }
        return null;
    }

    private function fromDatabase(string $hex): ?array
    {
        try {
            $event = $this->eventRepository->findLatestRelayListByPubkey($hex);
            if (!$event) {
                return null;
            }

            $result = $this->parseRelayListEvent($event);
            if (empty($result['all'])) {
                return null;
            }

            $this->logger->debug('UserRelayListService: DB hit', [
                'pubkey' => substr($hex, 0, 8),
                'relay_count' => count($result['all']),
                'age_hours' => round((time() - ($result['created_at'] ?? 0)) / 3600, 1),
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->warning('UserRelayListService: DB read error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Fetch kind 10002 relay list from profile relays, persist to DB, warm cache.
     */
    private function fromNetwork(string $hex): ?array
    {
        $this->logger->info('UserRelayListService: fetching NIP-65 relay list from network', [
            'pubkey' => substr($hex, 0, 8),
        ]);

        try {
            $profileRelays = $this->relayRegistry->getProfileRelays();
            $responses = $this->relayPool->sendToRelays(
                $profileRelays,
                function () use ($hex) {
                    $subscription = new Subscription();
                    $subscriptionId = $subscription->setId();
                    $filter = new Filter();
                    $filter->setKinds([KindsEnum::RELAY_LIST->value]);
                    $filter->setAuthors([$hex]);
                    $filter->setLimit(1);
                    return new RequestMessage($subscriptionId, [$filter]);
                }
            );

            // Collect events from all relay responses
            $events = [];
            foreach ($responses as $relayResponses) {
                foreach ($relayResponses as $response) {
                    if (isset($response->type) && $response->type === 'EVENT' && isset($response->event)) {
                        $events[] = $response->event;
                    }
                }
            }

            if (empty($events)) {
                $this->logger->debug('UserRelayListService: no NIP-65 relay list found on network', [
                    'pubkey' => substr($hex, 0, 8),
                ]);
                return null;
            }

            // Take the newest event
            usort($events, fn($a, $b) => ($b->created_at ?? 0) <=> ($a->created_at ?? 0));
            $latestEvent = $events[0];

            // Parse relay list from tags
            $result = ['read' => [], 'write' => [], 'all' => [], 'created_at' => $latestEvent->created_at ?? time()];

            foreach ($latestEvent->tags ?? [] as $tag) {
                if (!is_array($tag) || ($tag[0] ?? '') !== 'r') {
                    continue;
                }
                $relayUrl = $tag[1] ?? null;
                if (!$relayUrl || !$this->isValidRelay($relayUrl)) {
                    continue;
                }

                $marker = $tag[2] ?? null;
                if ($marker === 'read') {
                    $result['read'][] = $relayUrl;
                } elseif ($marker === 'write') {
                    $result['write'][] = $relayUrl;
                } else {
                    // No marker means both read and write
                    $result['read'][] = $relayUrl;
                    $result['write'][] = $relayUrl;
                }
                $result['all'][] = $relayUrl;
            }

            $result['read'] = array_values(array_unique($result['read']));
            $result['write'] = array_values(array_unique($result['write']));
            $result['all'] = array_values(array_unique($result['all']));

            if (empty($result['all'])) {
                return null;
            }

            // Write-through: persist to DB if newer
            $this->persistToDatabase($hex, $result);

            // Warm cache
            $this->writeCache($hex, $result);

            $this->logger->info('UserRelayListService: network fetch successful', [
                'pubkey' => substr($hex, 0, 8),
                'relay_count' => count($result['all']),
                'read_count' => count($result['read']),
                'write_count' => count($result['write']),
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->warning('UserRelayListService: network fetch failed', [
                'pubkey' => substr($hex, 0, 8),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Persistence
    // ------------------------------------------------------------------

    /**
     * Persist relay list to the Event table as a synthetic kind 10002 event.
     * Only updates if the new data is newer than what's already stored.
     */
    private function persistToDatabase(string $hex, array $relayList): void
    {
        try {
            $existing = $this->eventRepository->findLatestRelayListByPubkey($hex);
            $newCreatedAt = $relayList['created_at'] ?? time();

            // Only update if newer
            if ($existing && ($existing->getCreatedAt() >= $newCreatedAt)) {
                $this->logger->debug('UserRelayListService: DB already has newer relay list', [
                    'pubkey' => substr($hex, 0, 8),
                    'db_created_at' => $existing->getCreatedAt(),
                    'new_created_at' => $newCreatedAt,
                ]);
                return;
            }

            // Build tags in NIP-65 format
            $tags = [];
            $readSet = array_flip($relayList['read'] ?? []);
            $writeSet = array_flip($relayList['write'] ?? []);

            foreach ($relayList['all'] as $relayUrl) {
                $isRead = isset($readSet[$relayUrl]);
                $isWrite = isset($writeSet[$relayUrl]);

                if ($isRead && $isWrite) {
                    $tags[] = ['r', $relayUrl]; // no marker = both
                } elseif ($isRead) {
                    $tags[] = ['r', $relayUrl, 'read'];
                } elseif ($isWrite) {
                    $tags[] = ['r', $relayUrl, 'write'];
                } else {
                    $tags[] = ['r', $relayUrl]; // default: both
                }
            }

            if ($existing) {
                // Update existing event in place
                $existing->setTags($tags);
                $existing->setCreatedAt($newCreatedAt);
                $this->em->flush();

                $this->logger->debug('UserRelayListService: updated relay list in DB', [
                    'pubkey' => substr($hex, 0, 8),
                    'relay_count' => count($tags),
                ]);
            } else {
                // Create new synthetic event
                $event = new Event();
                $event->setId('relay_list_' . $hex . '_' . $newCreatedAt);
                $event->setKind(KindsEnum::RELAY_LIST->value);
                $event->setPubkey($hex);
                $event->setContent('');
                $event->setCreatedAt($newCreatedAt);
                $event->setTags($tags);
                $event->setSig('');
                $event->extractAndSetDTag();

                $this->em->persist($event);
                $this->em->flush();

                $this->logger->info('UserRelayListService: persisted new relay list to DB', [
                    'pubkey' => substr($hex, 0, 8),
                    'relay_count' => count($tags),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->warning('UserRelayListService: DB write failed', [
                'pubkey' => substr($hex, 0, 8),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function writeCache(string $hex, array $data): void
    {
        try {
            $item = $this->cache->getItem(self::CACHE_PREFIX . $hex);
            $item->set($data);
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);
        } catch (\Exception $e) {
            $this->logger->warning('UserRelayListService: cache write error', ['error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function parseRelayListEvent(Event $event): array
    {
        $result = ['read' => [], 'write' => [], 'all' => [], 'created_at' => $event->getCreatedAt()];

        foreach ($event->getTags() ?? [] as $tag) {
            if (!is_array($tag) || ($tag[0] ?? '') !== 'r' || empty($tag[1])) {
                continue;
            }

            $url = $tag[1];
            if (!$this->isValidRelay($url)) {
                continue;
            }

            $marker = $tag[2] ?? null;
            if ($marker === 'read') {
                $result['read'][] = $url;
            } elseif ($marker === 'write') {
                $result['write'][] = $url;
            } else {
                $result['read'][] = $url;
                $result['write'][] = $url;
            }
            $result['all'][] = $url;
        }

        $result['read'] = array_values(array_unique($result['read']));
        $result['write'] = array_values(array_unique($result['write']));
        $result['all'] = array_values(array_unique($result['all']));

        return $result;
    }

    private function toHex(string $pubkeyOrNpub): string
    {
        return str_starts_with($pubkeyOrNpub, 'npub1')
            ? NostrKeyUtil::npubToHex($pubkeyOrNpub)
            : $pubkeyOrNpub;
    }

    private function isValidRelay(string $url): bool
    {
        return str_starts_with($url, 'wss://') && !str_contains($url, 'localhost');
    }
}

