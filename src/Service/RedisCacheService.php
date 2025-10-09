<?php

namespace App\Service;

use App\Entity\Event;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

readonly class RedisCacheService
{

    public function __construct(
        private NostrClient     $nostrClient,
        private CacheInterface  $redisCache,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    )
    {
    }

    /**
     * @param string $npub
     * @return \stdClass
     */
    public function getMetadata(string $npub): \stdClass
    {
        $cacheKey = '0_' . $npub;
        try {
            return $this->redisCache->get($cacheKey, function (ItemInterface $item) use ($npub) {
                $item->expiresAfter(3600); // 1 hour, adjust as needed
                try {
                    $rawEvent = $this->nostrClient->getNpubMetadata($npub);
                } catch (\Exception $e) {
                    $this->logger->error('Error getting user data.', ['exception' => $e]);
                    $rawEvent = new \stdClass();
                    $rawEvent->content = json_encode([
                        'name' => substr($npub, 0, 8) . '…' . substr($npub, -4)
                    ]);
                    $rawEvent->tags = [];
                }

                // Parse content as JSON
                $contentData = json_decode($rawEvent->content ?? '{}');
                if (!$contentData) {
                    $contentData = new \stdClass();
                }

                // Fields that should be collected as arrays when multiple values exist
                $arrayFields = ['nip05', 'lud16', 'lud06'];
                $arrayCollectors = [];

                // Parse tags and merge/override content data
                // Common metadata tags: name, about, picture, banner, nip05, lud16, website, etc.
                $tags = $rawEvent->tags ?? [];
                foreach ($tags as $tag) {
                    if (is_array($tag) && count($tag) >= 2) {
                        $tagName = $tag[0];

                        // Check if this field should be collected as an array
                        if (in_array($tagName, $arrayFields)) {
                            if (!isset($arrayCollectors[$tagName])) {
                                $arrayCollectors[$tagName] = [];
                            }
                            // Collect all values from position 1 onwards (tag can have multiple values)
                            for ($i = 1; $i < count($tag); $i++) {
                                $arrayCollectors[$tagName][] = $tag[$i];
                            }
                        } else {
                            // Override content field with tag value (first occurrence wins for non-array fields)
                            // For non-array fields, only use the first value (tag[1])
                            if (!isset($contentData->$tagName) && isset($tag[1])) {
                                $contentData->$tagName = $tag[1];
                            }
                        }
                    }
                }

                // Merge array collectors into content data
                foreach ($arrayCollectors as $fieldName => $values) {
                    // Remove duplicates
                    $values = array_unique($values);
                    $contentData->$fieldName = $values;
                }

                // If content had a single value for an array field but no tags, convert to array
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
            });
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Error getting user data.', ['exception' => $e]);
            $content = new \stdClass();
            $content->name = substr($npub, 0, 8) . '…' . substr($npub, -4);
            return $content;
        }
    }

    /**
     * Get metadata with raw event for debugging purposes.
     *
     * @param string $npub
     * @return array{metadata: \stdClass, rawEvent: \stdClass}
     */
    public function getMetadataWithRawEvent(string $npub): array
    {
        $cacheKey = '0_with_raw_' . $npub;
        try {
            return $this->redisCache->get($cacheKey, function (ItemInterface $item) use ($npub) {
                $item->expiresAfter(3600); // 1 hour, adjust as needed
                try {
                    $rawEvent = $this->nostrClient->getNpubMetadata($npub);
                } catch (\Exception $e) {
                    $this->logger->error('Error getting user data.', ['exception' => $e]);
                    $rawEvent = new \stdClass();
                    $rawEvent->content = json_encode([
                        'name' => substr($npub, 0, 8) . '…' . substr($npub, -4)
                    ]);
                    $rawEvent->tags = [];
                }

                // Parse content as JSON
                $contentData = json_decode($rawEvent->content ?? '{}');
                if (!$contentData) {
                    $contentData = new \stdClass();
                }

                // Fields that should be collected as arrays when multiple values exist
                $arrayFields = ['nip05', 'lud16', 'lud06'];
                $arrayCollectors = [];

                // Parse tags and merge/override content data
                $tags = $rawEvent->tags ?? [];
                foreach ($tags as $tag) {
                    if (is_array($tag) && count($tag) >= 2) {
                        $tagName = $tag[0];

                        // Check if this field should be collected as an array
                        if (in_array($tagName, $arrayFields)) {
                            if (!isset($arrayCollectors[$tagName])) {
                                $arrayCollectors[$tagName] = [];
                            }
                            // Collect all values from position 1 onwards (tag can have multiple values)
                            for ($i = 1; $i < count($tag); $i++) {
                                $arrayCollectors[$tagName][] = $tag[$i];
                            }
                        } else {
                            // Override content field with tag value (first occurrence wins for non-array fields)
                            // For non-array fields, only use the first value (tag[1])
                            if (!isset($contentData->$tagName) && isset($tag[1])) {
                                $contentData->$tagName = $tag[1];
                            }
                        }
                    }
                }

                // Merge array collectors into content data
                foreach ($arrayCollectors as $fieldName => $values) {
                    // Remove duplicates
                    $values = array_unique($values);
                    $contentData->$fieldName = $values;
                }

                // If content had a single value for an array field but no tags, convert to array
                foreach ($arrayFields as $fieldName) {
                    if (isset($contentData->$fieldName) && !is_array($contentData->$fieldName)) {
                        $contentData->$fieldName = [$contentData->$fieldName];
                    }
                }

                return [
                    'metadata' => $contentData,
                    'rawEvent' => $rawEvent
                ];
            });
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Error getting user data with raw event.', ['exception' => $e]);
            $content = new \stdClass();
            $content->name = substr($npub, 0, 8) . '…' . substr($npub, -4);
            $rawEvent = new \stdClass();
            $rawEvent->content = json_encode($content);
            $rawEvent->tags = [];
            return [
                'metadata' => $content,
                'rawEvent' => $rawEvent
            ];
        }
    }

    public function getRelays($npub)
    {
        $cacheKey = '10002_' . $npub;

        try {
            return $this->redisCache->get($cacheKey, function (ItemInterface $item) use ($npub) {
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
        return $this->redisCache->get($key, function (ItemInterface $item) use ($slug) {
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
            $item = $this->redisCache->getItem($key);
            $item->set($index);
            $this->redisCache->save($item);
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
            return $this->redisCache->get($cacheKey, function (ItemInterface $item) use ($npub, $limit) {
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
     * @param string $npub The author's npub
     * @param int $page Page number (1-based)
     * @param int $pageSize Number of items per page
     * @return array ['events' => array, 'hasMore' => bool, 'total' => int]
     */
    public function getMediaEventsPaginated(string $npub, int $page = 1, int $pageSize = 60): array
    {
        // Cache key for all media events (not page-specific)
        $cacheKey = 'media_all_' . $npub;

        try {
            // Fetch and cache all media events
            $allMediaEvents = $this->redisCache->get($cacheKey, function (ItemInterface $item) use ($npub) {
                $item->expiresAfter(600); // 10 minutes cache

                try {
                    // Fetch a large batch to account for deduplication
                    // Nostr relays are unstable, so we fetch more than we need
                    $mediaEvents = $this->nostrClient->getAllMediaEventsForPubkey($npub, 200);

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
            return $this->redisCache->get($cacheKey, function (ItemInterface $item) use ($eventId, $relays) {
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

    /**
     * Get a parameterized replaceable event (naddr) with caching
     *
     * @param array $decodedData The decoded naddr data
     * @return object|null The event object or null if not found
     */
    public function getNaddrEvent(array $decodedData): ?object
    {
        $cacheKey = 'naddr_' . $decodedData['kind'] . '_' . $decodedData['pubkey'] . '_' . $decodedData['identifier'] . '_' . md5(json_encode($decodedData['relays'] ?? []));

        try {
            return $this->redisCache->get($cacheKey, function (ItemInterface $item) use ($decodedData) {
                $item->expiresAfter(1800); // 30 minutes cache for naddr events

                try {
                    $event = $this->nostrClient->getEventByNaddr($decodedData);
                    return $event;
                } catch (\Exception $e) {
                    $this->logger->error('Error getting naddr event.', ['exception' => $e, 'decodedData' => $decodedData]);
                    return null;
                }
            });
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Cache error getting naddr event.', ['exception' => $e, 'decodedData' => $decodedData]);
            return null;
        }
    }

    public function setMetadata(\swentel\nostr\Event\Event $event)
    {
        $key = new Key();
        $npub = $key->convertPublicKeyToBech32($event->getPublicKey());
        $cacheKey = '0_' . $npub;
        try {
            $item = $this->redisCache->getItem($cacheKey);
            $item->set(json_decode($event->getContent()));
            $item->expiresAfter(3600); // 1 hour
            $this->redisCache->save($item);
        } catch (\Exception $e) {
            $this->logger->error('Error setting user metadata.', ['exception' => $e]);
        }
    }
}
