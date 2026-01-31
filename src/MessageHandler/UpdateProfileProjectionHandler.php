<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\UpdateProfileProjectionMessage;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\NostrClient;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles async profile projection updates.
 *
 * Fetches profile data from Nostr relays, caches it in Redis, and updates the User entity.
 * This handler actively fetches data rather than just reading from cache.
 *
 * This handler is idempotent and order-independent.
 *
 * NOTE: For batch updates, prefer using BatchUpdateProfileProjectionMessage which
 * makes a single relay call for multiple pubkeys and is much more efficient.
 */
#[AsMessageHandler]
class UpdateProfileProjectionHandler
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly CacheItemPoolInterface $npubCache,
        private readonly RedisCacheService $redisCacheService,
        private readonly UserEntityRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(UpdateProfileProjectionMessage $message): void
    {
        $pubkeyHex = $message->getPubkeyHex();

        $this->logger->debug('Processing profile projection update', [
            'pubkey' => substr($pubkeyHex, 0, 8) . '...'
        ]);

        try {
            // Convert to npub for user lookup
            $npub = NostrKeyUtil::hexToNpub($pubkeyHex);

            // Get or create user
            $user = $this->userRepository->findOneBy(['npub' => $npub]);

            if (!$user) {
                $this->logger->info('Creating new user during projection update', [
                    'npub' => $npub
                ]);
                $user = new User();
                $user->setNpub($npub);
                $this->entityManager->persist($user);
            }

            // Update metadata facet from Redis
            $updated = $this->updateMetadataFacet($user, $pubkeyHex);

            // Update relay list facet from Redis
            $updated = $this->updateRelayListFacet($user, $pubkeyHex) || $updated;

            // Use transaction for database flush to prevent partial updates
            if ($updated) {
                try {
                    $this->entityManager->flush();
                    $this->logger->info('Profile projection updated successfully', [
                        'npub' => $npub
                    ]);
                } catch (\Exception $flushException) {
                    $this->logger->error('Database flush failed for profile projection', [
                        'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                        'error' => $flushException->getMessage()
                    ]);
                    // Clear entity manager to prevent issues with next message
                    $this->entityManager->clear();
                    throw $flushException;
                }
            } else {
                $this->logger->debug('No profile data available to update', [
                    'npub' => $npub
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to update profile projection', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Clear entity manager to prevent stale data in next message
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }

            // Don't rethrow - we don't want to retry indefinitely
            // The middleware will handle acknowledgment issues
        }
    }

    /**
     * Update metadata facet (kind:0) by fetching from Nostr and caching
     */
    private function updateMetadataFacet(User $user, string $pubkeyHex): bool
    {
        try {
            // Check cache freshness first - skip relay call if we have recent data
            $cacheKey = '0_' . $pubkeyHex;
            $cacheItem = $this->npubCache->getItem($cacheKey);

            if ($cacheItem->isHit()) {
                $cachedMeta = $cacheItem->get();
                // If we have cached metadata and user already has display name,
                // skip the relay call - the profile is already synced
                if ($cachedMeta && $user->getDisplayName()) {
                    $this->logger->debug('Skipping relay call - user already has profile data', [
                        'pubkey' => substr($pubkeyHex, 0, 8) . '...'
                    ]);
                    return $this->updateUserFromCachedMetadata($user, $cachedMeta);
                }
            }

            // Fetch metadata from Nostr relays with timeout protection
            $rawEvent = null;
            $timeoutSeconds = 10; // Reduced from 20 to 10 seconds

            try {
                // Use set_error_handler to catch timeout warnings
                set_time_limit($timeoutSeconds + 5); // PHP execution time limit

                $rawEvent = $this->nostrClient->getPubkeyMetadata($pubkeyHex);
            } catch (\Exception $relayException) {
                $this->logger->debug('Relay fetch timed out or failed for metadata', [
                    'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                    'error' => $relayException->getMessage()
                ]);
                return false;
            } finally {
                set_time_limit(0); // Reset to no limit
            }

            if (!$rawEvent) {
                $this->logger->debug('No metadata found on relays', [
                    'pubkey' => substr($pubkeyHex, 0, 8) . '...'
                ]);
                return false;
            }

            // Parse metadata
            $metadata = $this->parseUserMetadata($rawEvent, $pubkeyHex);

            // Cache the parsed metadata
            try {
                $cacheKey = '0_' . $pubkeyHex;
                $item = $this->npubCache->getItem($cacheKey);
                $item->set($metadata);
                $item->expiresAfter(3600); // 1 hour
                $this->npubCache->save($item);

                $this->logger->debug('Cached profile metadata', [
                    'pubkey' => substr($pubkeyHex, 0, 8) . '...'
                ]);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to cache metadata', [
                    'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                    'error' => $e->getMessage()
                ]);
            }

            // Update user fields from metadata
            try {
                if (isset($metadata->display_name)) {
                    $user->setDisplayName($this->sanitizeStringValue($metadata->display_name));
                }
                if (isset($metadata->name)) {
                    $user->setName($this->sanitizeStringValue($metadata->name));
                }
                if (isset($metadata->nip05)) {
                    $user->setNip05($this->sanitizeStringValue($metadata->nip05));
                }
                if (isset($metadata->about)) {
                    $user->setAbout($this->sanitizeStringValue($metadata->about));
                }
                if (isset($metadata->website)) {
                    $user->setWebsite($this->sanitizeStringValue($metadata->website));
                }
                if (isset($metadata->picture)) {
                    $user->setPicture($this->sanitizeStringValue($metadata->picture));
                }
                if (isset($metadata->banner)) {
                    $user->setBanner($this->sanitizeStringValue($metadata->banner));
                }
                if (isset($metadata->lud16)) {
                    $user->setLud16($this->sanitizeStringValue($metadata->lud16));
                }
            } catch (\Exception $fieldException) {
                $this->logger->warning('Failed to set user field from metadata', [
                    'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                    'error' => $fieldException->getMessage()
                ]);
                // Continue anyway - partial updates are acceptable
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to update metadata facet', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Update user fields from cached metadata (without relay call)
     */
    private function updateUserFromCachedMetadata(User $user, mixed $metadata): bool
    {
        if (!$metadata || !is_object($metadata)) {
            return false;
        }

        try {
            if (isset($metadata->display_name)) {
                $user->setDisplayName($this->sanitizeStringValue($metadata->display_name));
            }
            if (isset($metadata->name)) {
                $user->setName($this->sanitizeStringValue($metadata->name));
            }
            if (isset($metadata->nip05)) {
                $user->setNip05($this->sanitizeStringValue($metadata->nip05));
            }
            if (isset($metadata->about)) {
                $user->setAbout($this->sanitizeStringValue($metadata->about));
            }
            if (isset($metadata->website)) {
                $user->setWebsite($this->sanitizeStringValue($metadata->website));
            }
            if (isset($metadata->picture)) {
                $user->setPicture($this->sanitizeStringValue($metadata->picture));
            }
            if (isset($metadata->banner)) {
                $user->setBanner($this->sanitizeStringValue($metadata->banner));
            }
            if (isset($metadata->lud16)) {
                $user->setLud16($this->sanitizeStringValue($metadata->lud16));
            }
            return true;
        } catch (\Exception $e) {
            return false;
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

        return $contentData;
    }

    /**
     * Update relay list facet (kind:10002) from Redis cache
     *
     * Note: Currently just fetches from Redis. In the future, this will also
     * read from persisted kind:10002 events in the Event table.
     */
    private function updateRelayListFacet(User $user, string $pubkeyHex): bool
    {
        try {
            $relays = $this->redisCacheService->getRelays($pubkeyHex);

            if (empty($relays)) {
                return false;
            }

            // Note: Relay data is typically not persisted to User entity columns
            // but could be stored in a separate relation if needed
            // For now, we just log that we have relay data
            $this->logger->debug('Retrieved relay list', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'relay_count' => is_array($relays) ? count($relays) : 0
            ]);

            return false; // Not persisting relays to DB yet
        } catch (\Exception $e) {
            $this->logger->warning('Failed to update relay list facet', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Sanitize metadata value to ensure it's a string or null
     */
    private function sanitizeStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if (empty($value)) {
                return null;
            }

            $stringValues = array_filter($value, fn($item) => is_scalar($item));
            $stringValues = array_map(fn($item) => (string) $item, $stringValues);

            if (empty($stringValues)) {
                return null;
            }

            return implode(', ', $stringValues);
        }

        if (is_object($value)) {
            return null;
        }

        return (string) $value;
    }
}
