<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\ItemInterface;

class RedisCacheService
{
    private ?RedisViewStore $viewStore = null;

    public function __construct(
        private readonly NostrClient            $nostrClient,
        private readonly CacheItemPoolInterface $npubCache,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface        $logger
    ) {}

    /**
     * Inject RedisViewStore (using setter to avoid circular dependency)
     */
    public function setViewStore(RedisViewStore $viewStore): void
    {
        $this->viewStore = $viewStore;
    }

    /**
     * Generate the cache key for user metadata (hex pubkey only).
     */
    private function getUserCacheKey(string $pubkey): string
    {
        return '0_' . $pubkey;
    }

    /**
     * @param string $pubkey Hex-encoded public key
     * @return \stdClass
     * @throws InvalidArgumentException
     */
    public function getMetadata(string $pubkey): \stdClass
    {
        if (!NostrKeyUtil::isHexPubkey($pubkey)) {
            throw new \InvalidArgumentException('getMetadata expects hex pubkey');
        }
        $cacheKey = $this->getUserCacheKey($pubkey);
        // Default content if fetching/parsing fails
        $content = new \stdClass();
        // Pubkey to npub
        $npub = NostrKeyUtil::hexToNpub($pubkey);
        $defaultName = '@' . substr($npub, 5, 4) . '…' . substr($npub, -4);
        $content->name = $defaultName;

        try {
            $content = $this->npubCache->get($cacheKey, function (ItemInterface $item) use ($pubkey) {
                $item->expiresAfter(3600); // 1 hour, adjust as needed
                $rawEvent = $this->fetchRawUserEvent($pubkey);
                return $this->parseUserMetadata($rawEvent, $pubkey);
            });
        } catch (Exception|InvalidArgumentException $e) {
            $this->logger->error('Error getting user data.', ['exception' => $e]);
        }
        // If content is still default, delete cache to retry next time
        if (isset($content->name) && $content->name === $defaultName
            && $this->npubCache->hasItem($cacheKey)) {
            try {
                $this->npubCache->deleteItem($cacheKey);
            } catch (\Exception $e) {
                $this->logger->error('Error deleting user cache item.', ['exception' => $e]);
            }
        }
        return $content;
    }

    /**
     * Fetch raw user event from Nostr client, with error fallback.
     * @param string $pubkey Hex-encoded public key
     * @throws Exception
     */
    private function fetchRawUserEvent(string $pubkey): \stdClass
    {
        try {
            return $this->nostrClient->getPubkeyMetadata($pubkey);
        } catch (\Exception $e) {
            $this->logger->error('Error getting user data.', ['exception' => $e]);
            // Rethrow exception to be caught in getMetadata
            throw $e;
        }
    }

    /**
     * Parse user metadata from a raw event object.
     */
    private function parseUserMetadata(\stdClass $rawEvent, string $pubkey): \stdClass
    {
        $contentData = json_decode($rawEvent->content ?? '{}');
        if (!$contentData) {
            $contentData = new \stdClass();
        }
        $arrayFields = ['nip05', 'lud16', 'lud06'];
        $arrayCollectors = [];
        $tags = $rawEvent->tags ?? [];
        foreach ($tags as $tag) {
            if (is_array($tag) && count($tag) >= 2) {
                $tagName = $tag[0];
                if (in_array($tagName, $arrayFields, true)) {
                    if (!isset($arrayCollectors[$tagName])) {
                        $arrayCollectors[$tagName] = [];
                    }
                    for ($i = 1; $i < count($tag); $i++) {
                        $arrayCollectors[$tagName][] = $tag[$i];
                    }
                } elseif (!isset($contentData->$tagName) && isset($tag[1])) {
                    $contentData->$tagName = $tag[1];
                }
            }
        }
        foreach ($arrayCollectors as $fieldName => $values) {
            $contentData->$fieldName = array_unique($values);
        }
        foreach ($arrayFields as $fieldName) {
            if (isset($contentData->$fieldName) && !is_array($contentData->$fieldName)) {
                $contentData->$fieldName = [$contentData->$fieldName];
            }
        }
        $this->logger->info('Metadata (with tags):', [
            'meta' => json_encode($contentData),
            'tags' => json_encode($tags)
        ]);
        return $contentData;
    }

    /**
     * Fetch metadata for multiple pubkeys at once using Redis getItems.
     * Batch fetches missing pubkeys from Nostr relays (local relay first, then public fallback).
     *
     * @param string[] $pubkeys Array of hex pubkeys
     * @return array<string, \stdClass> Map of pubkey => metadata
     * @throws InvalidArgumentException
     */
    public function getMultipleMetadata(array $pubkeys): array
    {
        foreach ($pubkeys as $pubkey) {
            if (!NostrKeyUtil::isHexPubkey($pubkey)) {
                throw new \InvalidArgumentException('getMultipleMetadata expects all hex pubkeys');
            }
        }
        $result = [];
        $cacheKeys = array_map(fn($pubkey) => $this->getUserCacheKey($pubkey), $pubkeys);
        $pubkeyMap = array_combine($cacheKeys, $pubkeys);
        $items = $this->npubCache->getItems($cacheKeys);
        foreach ($items as $cacheKey => $item) {
            $pubkey = $pubkeyMap[$cacheKey];
            if ($item->isHit()) {
                $result[$pubkey] = $item->get();
            }
        }

        // Batch fetch missing pubkeys using NostrClient (local relay first, fallback to public)
        $missedPubkeys = array_diff($pubkeys, array_keys($result));
        if (!empty($missedPubkeys)) {
            try {
                $batchMetadata = $this->nostrClient->getMetadataForPubkeys(array_values($missedPubkeys), true);

                foreach ($missedPubkeys as $pubkey) {
                    if (isset($batchMetadata[$pubkey])) {
                        // Parse and cache the metadata
                        $parsed = $this->parseUserMetadata($batchMetadata[$pubkey], $pubkey);
                        $result[$pubkey] = $parsed;

                        // Store in cache for future use
                        try {
                            $cacheKey = $this->getUserCacheKey($pubkey);
                            $item = $this->npubCache->getItem($cacheKey);
                            $item->set($parsed);
                            $item->expiresAfter(3600); // 1 hour
                            $this->npubCache->save($item);
                        } catch (\Throwable $e) {
                            $this->logger->warning('Failed to cache batch-fetched metadata', [
                                'pubkey' => $pubkey,
                                'error' => $e->getMessage()
                            ]);
                        }
                    } else {
                        // Fallback to default metadata if not found
                        $npub = NostrKeyUtil::hexToNpub($pubkey);
                        $defaultName = '@' . substr($npub, 5, 4) . '…' . substr($npub, -4);
                        $content = new \stdClass();
                        $content->name = $defaultName;
                        $result[$pubkey] = $content;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Batch metadata fetch failed, using defaults', [
                    'error' => $e->getMessage(),
                    'pubkeys_count' => count($missedPubkeys)
                ]);

                // Fallback: create default metadata for all missed pubkeys
                foreach ($missedPubkeys as $pubkey) {
                    $npub = NostrKeyUtil::hexToNpub($pubkey);
                    $defaultName = '@' . substr($npub, 5, 4) . '…' . substr($npub, -4);
                    $content = new \stdClass();
                    $content->name = $defaultName;
                    $result[$pubkey] = $content;
                }
            }
        }

        return $result;
    }

    public function getRelays($npub)
    {
        $cacheKey = '10002_' . $npub;

        try {
            return $this->npubCache->get($cacheKey, function (ItemInterface $item) use ($npub) {
                $item->expiresAfter(3600); // 1 hour, adjust as needed
                try {
                    $relays = $this->nostrClient->getNpubRelays($npub);
                } catch (\Exception $e) {
                    $this->logger->error('Error getting user relays.', ['exception' => $e]);
                }
                return $relays ?? [];
            });
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Error getting user relays.', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get a magazine index object by key.
     * @param string $slug
     * @return object|null
     * @throws InvalidArgumentException
     */
    public function getMagazineIndex(string $slug): ?object
    {
        // redis cache lookup of magazine index by slug
        $key = 'magazine-index-' . $slug;
        return $this->npubCache->get($key, function (ItemInterface $item) use ($slug) {
            $item->expiresAfter(3600); // 1 hour

            $nzines = $this->entityManager->getRepository(Event::class)->findBy(['kind' => KindsEnum::PUBLICATION_INDEX]);
            // filter, only keep type === magazine and slug === $mag
            $nzines = array_filter($nzines, function ($index) use ($slug) {
                // look for slug
                return $index->getSlug() === $slug;
            });

            if (count($nzines) === 0) {
                return new Response('Magazine not found', 404);
            }
            // sort by createdAt, keep newest
            usort($nzines, function ($a, $b) {
                return $b->getCreatedAt() <=> $a->getCreatedAt();
            });
            $nzine = array_shift($nzines);

            $this->logger->info('Magazine lookup', ['mag' => $slug, 'found' => json_encode($nzine)]);

            return $nzine;
        });
    }

    /**
     * Update a magazine index by inserting a new article tag at the top.
     * @param string $key
     * @param array $articleTag The tag array, e.g. ['a', 'article:slug', ...]
     * @return bool
     */
    public function addArticleToIndex(string $key, array $articleTag): bool
    {
        $index = $this->getMagazineIndex($key);
        if (!$index || !isset($index->tags) || !is_array($index->tags)) {
            $this->logger->error('Invalid index object or missing tags array.');
            return false;
        }
        // Insert the new article tag at the top
        array_unshift($index->tags, $articleTag);
        try {
            $item = $this->npubCache->getItem($key);
            $item->set($index);
            $this->npubCache->save($item);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error updating magazine index.', ['exception' => $e]);
            return false;
        }
    }

    /**
     * Get media events (pictures and videos) for a given npub with caching
     *
     * @param string $npub The author's npub
     * @param int $limit Maximum number of events to fetch
     * @return array Array of media events
     */
    public function getMediaEvents(string $npub, int $limit = 30): array
    {
        $cacheKey = 'media_' . $npub . '_' . $limit;
        try {
            return $this->npubCache->get($cacheKey, function (ItemInterface $item) use ($npub, $limit) {
                $item->expiresAfter(600); // 10 minutes cache for media events

                try {
                    // Use the optimized single-request method
                    $mediaEvents = $this->nostrClient->getAllMediaEventsForPubkey($npub, $limit);

                    // Deduplicate by event ID
                    $uniqueEvents = [];
                    foreach ($mediaEvents as $event) {
                        if (!isset($uniqueEvents[$event->id])) {
                            $uniqueEvents[$event->id] = $event;
                        }
                    }

                    // Convert back to indexed array and sort by date (newest first)
                    $mediaEvents = array_values($uniqueEvents);
                    usort($mediaEvents, function ($a, $b) {
                        return $b->created_at <=> $a->created_at;
                    });

                    return $mediaEvents;
                } catch (\Exception $e) {
                    $this->logger->error('Error getting media events.', ['exception' => $e, 'npub' => $npub]);
                    return [];
                }
            });
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Cache error getting media events.', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get all media events for pagination (fetches large batch, caches, returns paginated)
     *
     * @param string $pubkey The author's pubkey
     * @param int $page Page number (1-based)
     * @param int $pageSize Number of items per page
     * @return array ['events' => array, 'hasMore' => bool, 'total' => int]
     */
    public function getMediaEventsPaginated(string $pubkey, int $page = 1, int $pageSize = 60): array
    {
        // Cache key for all media events (not page-specific)
        $cacheKey = 'media_all_' . $pubkey;

        try {
            // Fetch and cache all media events
            $allMediaEvents = $this->npubCache->get($cacheKey, function (ItemInterface $item) use ($pubkey) {
                $item->expiresAfter(600); // 10 minutes cache

                try {
                    // Fetch from database instead of Nostr relays for better reliability
                    $eventRepo = $this->entityManager->getRepository(Event::class);
                    $dbEvents = $eventRepo->findMediaEventsByPubkey($pubkey, [20, 21, 22], 200);

                    // Convert Event entities to stdClass objects (same format as Nostr events)
                    $mediaEvents = [];
                    foreach ($dbEvents as $event) {
                        $obj = new \stdClass();
                        $obj->id = $event->getId();
                        $obj->pubkey = $event->getPubkey();
                        $obj->created_at = $event->getCreatedAt();
                        $obj->kind = $event->getKind();
                        $obj->tags = $event->getTags();
                        $obj->content = $event->getContent();
                        $obj->sig = $event->getSig();
                        $mediaEvents[] = $obj;
                    }

                    // Already sorted by created_at DESC from the query
                    return $mediaEvents;
                } catch (\Exception $e) {
                    $this->logger->error('Error getting media events from database.', [
                        'exception' => $e,
                        'pubkey' => $pubkey
                    ]);
                    return [];
                }
            });

            // Perform pagination on cached results
            $total = count($allMediaEvents);
            $offset = ($page - 1) * $pageSize;

            // Get the page slice
            $pageEvents = array_slice($allMediaEvents, $offset, $pageSize);

            // Check if there are more pages
            $hasMore = ($offset + $pageSize) < $total;

            return [
                'events' => $pageEvents,
                'hasMore' => $hasMore,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
            ];

        } catch (InvalidArgumentException $e) {
            $this->logger->error('Cache error getting paginated media events.', ['exception' => $e]);
            return [
                'events' => [],
                'hasMore' => false,
                'total' => 0,
                'page' => $page,
                'pageSize' => $pageSize,
            ];
        }
    }

    /**
     * Get a single event by ID with caching
     *
     * @param string $eventId The event ID
     * @param array|null $relays Optional relays to query
     * @return object|null The event object or null if not found
     */
    public function getEvent(string $eventId, ?array $relays = null): ?object
    {
        $cacheKey = 'event_' . $eventId . ($relays ? '_' . md5(json_encode($relays)) : '');

        try {
            return $this->npubCache->get($cacheKey, function (ItemInterface $item) use ($eventId, $relays) {
                $item->expiresAfter(1800); // 30 minutes cache for events

                try {
                    $event = $this->nostrClient->getEventById($eventId, $relays);
                    return $event;
                } catch (\Exception $e) {
                    $this->logger->error('Error getting event.', ['exception' => $e, 'eventId' => $eventId]);
                    return null;
                }
            });
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Cache error getting event.', ['exception' => $e, 'eventId' => $eventId]);
            return null;
        }
    }

    public function setMetadata(\swentel\nostr\Event\Event $event): void
    {
        $key = new Key();
        $npub = $key->convertPublicKeyToBech32($event->getPublicKey());
        $cacheKey = '0_' . $npub;
        try {
            $item = $this->npubCache->getItem($cacheKey);
            $item->set(json_decode($event->getContent()));
            $item->expiresAfter(3600); // 1 hour
            $this->npubCache->save($item);
        } catch (\Exception $e) {
            $this->logger->error('Error setting user metadata.', ['exception' => $e]);
        }
    }

    private function commentsKey(string $coordinate): string
    {
        return 'comments_' . $coordinate;
    }

    /** Return cached comments payload or null */
    public function getCommentsPayload(string $coordinate): ?array
    {
        $key = $this->commentsKey($coordinate);
        try {
            $item = $this->npubCache->getItem($key);
            if ($item->isHit()) {
                $val = $item->get();
                return is_array($val) ? $val : null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Comments cache get failed', ['e' => $e->getMessage(), 'coord' => $coordinate]);
        }
        return null;
    }

    /** Save payload with TTL (seconds) */
    public function setCommentsPayload(string $coordinate, array $payload, int $ttl): void
    {
        $key = $this->commentsKey($coordinate);
        try {
            $item = $this->npubCache->getItem($key);
            $item->set($payload);
            $item->expiresAfter($ttl);
            $this->npubCache->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('Comments cache set failed', ['e' => $e->getMessage(), 'coord' => $coordinate]);
        }
    }

    /**
     * Invalidate Redis views that contain this profile
     * Called when profile metadata (kind 0) is updated
     *
     * @param string $pubkey Hex pubkey whose profile was updated
     */
    public function invalidateProfileViews(string $pubkey): void
    {
        if ($this->viewStore === null) {
            return; // ViewStore not injected, skip invalidation
        }

        try {
            // Invalidate user's articles view
            $this->viewStore->invalidateUserArticles($pubkey);

            // For now, we invalidate all latest views since we don't track which profiles are in them
            // In future, this could be more selective
            $this->viewStore->invalidateAll();

            $this->logger->debug('Invalidated Redis views for profile update', [
                'pubkey' => $pubkey,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate Redis views', [
                'pubkey' => $pubkey,
                'error' => $e->getMessage(),
            ]);
        }
    }

}
