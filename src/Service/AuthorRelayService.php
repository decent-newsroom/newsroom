<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\KindsEnum;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Subscription\Subscription;

/**
 * Service for discovering and managing author relay lists (NIP-65)
 */
class AuthorRelayService
{
    private const FALLBACK_RELAYS = [
        'wss://theforest.nostr1.com',
        'wss://nostr.land',
        'wss://relay.primal.net',
        'wss://purplepag.es',
    ];

    private const PROFILE_RELAYS = [
        'wss://purplepag.es',
        'wss://theforest.nostr1.com',
    ];

    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly NostrRelayPool $relayPool,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ?string $nostrDefaultRelay = null
    ) {}

    /**
     * Get relay list for an author (NIP-65 kind 10002)
     * @return array{read: string[], write: string[], all: string[]}
     */
    public function getAuthorRelays(string $pubkey, bool $forceRefresh = false): array
    {
        $cacheKey = 'author_relays_' . $pubkey;

        if (!$forceRefresh) {
            try {
                $cached = $this->cache->getItem($cacheKey);
                if ($cached->isHit()) {
                    return $cached->get();
                }
            } catch (\Exception $e) {
                $this->logger->warning('Cache error', ['error' => $e->getMessage()]);
            }
        }

        $relayList = $this->fetchRelayList($pubkey);

        try {
            $cacheItem = $this->cache->getItem($cacheKey);
            $cacheItem->set($relayList);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);
        } catch (\Exception $e) {
            $this->logger->warning('Cache save error', ['error' => $e->getMessage()]);
        }

        return $relayList;
    }

    /**
     * Get prioritized relay URLs for fetching content
     */
    public function getRelaysForFetching(string $pubkey, int $limit = 5): array
    {
        $authorRelays = $this->getAuthorRelays($pubkey);
        $relays = [];

        if ($this->nostrDefaultRelay) {
            $relays[] = $this->nostrDefaultRelay;
        }

        foreach ($authorRelays['read'] as $relay) {
            if (!in_array($relay, $relays) && $this->isValidRelay($relay)) {
                $relays[] = $relay;
            }
        }

        foreach ($authorRelays['all'] as $relay) {
            if (!in_array($relay, $relays) && $this->isValidRelay($relay)) {
                $relays[] = $relay;
            }
        }

        foreach (self::FALLBACK_RELAYS as $relay) {
            if (!in_array($relay, $relays)) {
                $relays[] = $relay;
            }
        }

        return array_slice($relays, 0, $limit);
    }

    /**
     * Get prioritized relay URLs for publishing content
     */
    public function getRelaysForPublishing(string $pubkey, int $limit = 5): array
    {
        $authorRelays = $this->getAuthorRelays($pubkey);
        $relays = [];

        if ($this->nostrDefaultRelay) {
            $relays[] = $this->nostrDefaultRelay;
        }

        foreach ($authorRelays['write'] as $relay) {
            if (!in_array($relay, $relays) && $this->isValidRelay($relay)) {
                $relays[] = $relay;
            }
        }

        foreach (self::FALLBACK_RELAYS as $relay) {
            if (!in_array($relay, $relays)) {
                $relays[] = $relay;
            }
        }

        return array_slice($relays, 0, $limit);
    }

    private function fetchRelayList(string $pubkey): array
    {
        $this->logger->info('Fetching NIP-65 relay list', ['pubkey' => $pubkey]);
        $result = ['read' => [], 'write' => [], 'all' => []];

        try {
            $responses = $this->relayPool->sendToRelays(
                self::PROFILE_RELAYS,
                function () use ($pubkey) {
                    $subscription = new Subscription();
                    $subscriptionId = $subscription->setId();
                    $filter = new Filter();
                    $filter->setKinds([KindsEnum::RELAY_LIST->value]);
                    $filter->setAuthors([$pubkey]);
                    $filter->setLimit(5);
                    return new RequestMessage($subscriptionId, [$filter]);
                }
            );

            $events = [];
            foreach ($responses as $relayResponses) {
                foreach ($relayResponses as $response) {
                    if (isset($response->type) && $response->type === 'EVENT' && isset($response->event)) {
                        $events[] = $response->event;
                    }
                }
            }

            if (empty($events)) {
                $this->logger->info('No NIP-65 relay list found, using fallbacks', ['pubkey' => $pubkey]);
                $result['all'] = self::FALLBACK_RELAYS;
                return $result;
            }

            usort($events, fn($a, $b) => ($b->created_at ?? 0) <=> ($a->created_at ?? 0));
            $latestEvent = $events[0];

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

            $result['read'] = array_unique($result['read']);
            $result['write'] = array_unique($result['write']);
            $result['all'] = array_unique($result['all']);

            $this->logger->info('Parsed NIP-65 relay list', [
                'pubkey' => $pubkey,
                'read_count' => count($result['read']),
                'write_count' => count($result['write']),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error fetching relay list', [
                'pubkey' => $pubkey,
                'error' => $e->getMessage(),
            ]);
            $result['all'] = self::FALLBACK_RELAYS;
        }

        return $result;
    }

    private function isValidRelay(string $url): bool
    {
        return str_starts_with($url, 'wss://') && !str_contains($url, 'localhost');
    }

    public function getFallbackRelays(): array
    {
        $relays = [];
        if ($this->nostrDefaultRelay) {
            $relays[] = $this->nostrDefaultRelay;
        }
        return array_merge($relays, self::FALLBACK_RELAYS);
    }

    public function invalidateCache(string $pubkey): void
    {
        try {
            $this->cache->deleteItem('author_relays_' . $pubkey);
        } catch (\Exception $e) {
            $this->logger->warning('Cache invalidation failed', ['error' => $e->getMessage()]);
        }
    }
}
