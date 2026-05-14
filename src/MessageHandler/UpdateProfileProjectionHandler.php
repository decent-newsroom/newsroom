<?php

namespace App\MessageHandler;

use App\Entity\Event;
use App\Entity\User;
use App\Message\UpdateProfileProjectionMessage;
use App\Repository\EventRepository;
use App\Repository\UserEntityRepository;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
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
    /**
     * Minimum interval between metadata refreshes for a single pubkey.
     *
     * Acts as a debounce so repeated dispatches (e.g. one per ingested article
     * from a hydration sweep) collapse into at most one DB lookup / relay call
     * per pubkey per window. The periodic ProfileRefreshWorker uses a 2 h
     * stale threshold, so this stays well below that.
     */
    private const REFRESH_DEBOUNCE_SECONDS = 1800; // 30 minutes

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly CacheItemPoolInterface $npubCache,
        private readonly UserEntityRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly UserRelayListService $userRelayListService,
        private readonly GenericEventProjector $genericEventProjector,
        private readonly EventRepository $eventRepository,
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
     * Update metadata facet (kind:0).
     *
     * Resolution order (cheapest first):
     *   1. Debounce — skip entirely if the user was refreshed within
     *      REFRESH_DEBOUNCE_SECONDS. Collapses storms of dispatches (e.g.
     *      one-per-article from hydration) into a single effective call.
     *   2. Local DB — look up the latest kind:0 in the Event table. Local
     *      relay subscriptions feed strfry → DB, so by the time we get here
     *      the event is usually already persisted. No network call needed.
     *   3. Relay fallback — only if nothing is in the DB do we query the
     *      profile relay (purplepag.es) for a single pubkey.
     *
     * Sets `lastMetadataRefresh` on every successful pass (including DB-only
     * and relay-miss outcomes) so the debounce engages on the next dispatch.
     */
    private function updateMetadataFacet(User $user, string $pubkeyHex): bool
    {
        // ── 1. Debounce ────────────────────────────────────────────────
        $lastRefresh = $user->getLastMetadataRefresh();
        if ($lastRefresh !== null) {
            $age = time() - $lastRefresh->getTimestamp();
            if ($age < self::REFRESH_DEBOUNCE_SECONDS) {
                $this->logger->debug('Skipping profile refresh — within debounce window', [
                    'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                    'age_seconds' => $age,
                ]);
                return false;
            }
        }

        try {
            // ── 2. DB-first lookup ─────────────────────────────────────
            $dbEvent = $this->eventRepository->findLatestByPubkeyAndKind($pubkeyHex, 0);
            if ($dbEvent !== null) {
                $rawEvent = $this->buildRawEventFromDb($dbEvent);
                $this->applyMetadata($user, $rawEvent, $pubkeyHex, source: 'db');
                $user->setLastMetadataRefresh(new \DateTimeImmutable());
                return true;
            }

            // ── 3. Relay fallback ──────────────────────────────────────
            $rawEvent = null;
            $timeoutSeconds = 10;

            try {
                set_time_limit($timeoutSeconds + 5);
                $rawEvent = $this->nostrClient->getPubkeyMetadata($pubkeyHex);
            } catch (\Exception $relayException) {
                $this->logger->debug('Relay fetch failed for metadata', [
                    'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                    'error' => $relayException->getMessage(),
                ]);
            } finally {
                set_time_limit(0);
            }

            if (!$rawEvent) {
                // Negative cache: stamp the refresh timestamp so we don't
                // re-query the relay for users who genuinely have no kind:0.
                $user->setLastMetadataRefresh(new \DateTimeImmutable());
                $this->logger->debug('No metadata found in DB or on relays', [
                    'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                ]);
                return false;
            }

            // Persist raw kind:0 to Event table so the next dispatch hits
            // the DB-first path instead of the relay.
            try {
                $this->genericEventProjector->projectEventFromNostrEvent($rawEvent, 'relay-fetch');
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to persist kind:0 event to Event table', [
                    'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                    'error' => $e->getMessage(),
                ]);
            }

            $this->applyMetadata($user, $rawEvent, $pubkeyHex, source: 'relay');
            $user->setLastMetadataRefresh(new \DateTimeImmutable());
            return true;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to update metadata facet', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Reconstruct the loose stdClass shape that the rest of the metadata
     * pipeline expects (mirrors what NostrClient::getPubkeyMetadata returns)
     * from a persisted Event row.
     */
    private function buildRawEventFromDb(Event $event): \stdClass
    {
        $raw = new \stdClass();
        $raw->id         = $event->getId();
        $raw->pubkey     = $event->getPubkey();
        $raw->kind       = $event->getKind();
        $raw->content    = $event->getContent();
        $raw->tags       = $event->getTags();
        $raw->created_at = $event->getCreatedAt();
        $raw->sig        = $event->getSig();
        return $raw;
    }

    /**
     * Parse, cache, and apply kind:0 metadata to a User entity.
     */
    private function applyMetadata(User $user, \stdClass $rawEvent, string $pubkeyHex, string $source): void
    {
        $metadata = $this->parseUserMetadata($rawEvent, $pubkeyHex);

        // Refresh Redis cache so subsequent reads via RedisCacheService hit.
        try {
            $cacheKey = '0_' . $pubkeyHex;
            $item = $this->npubCache->getItem($cacheKey);
            $item->set($metadata);
            $item->expiresAfter(3600);
            $this->npubCache->save($item);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to cache metadata', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
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
        } catch (\Exception $fieldException) {
            $this->logger->warning('Failed to set user field from metadata', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error' => $fieldException->getMessage(),
            ]);
        }

        $this->logger->debug('Applied kind:0 metadata to user', [
            'pubkey' => substr($pubkeyHex, 0, 8) . '...',
            'source' => $source,
        ]);
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
     * Update relay list facet (kind:10002) using UserRelayListService
     *
     * This runs asynchronously so it can make network calls safely.
     * The relays are fetched via stale-while-revalidate and persisted to User entity.
     */
    private function updateRelayListFacet(User $user, string $pubkeyHex): bool
    {
        try {
            // Use UserRelayListService with revalidation to fetch, persist to DB, and cache relays
            // This is async so network calls are safe here
            $this->userRelayListService->revalidate($pubkeyHex);
            // Use getRelayListForDisplay() — the user entity is read by frontend code
            // (editor panel, JS publishing); it must contain public relay URLs, never
            // the internal ws://strfry:7777 Docker hostname.
            $relays = $this->userRelayListService->getRelayListForDisplay($pubkeyHex);

            if (empty($relays['all'])) {
                $this->logger->debug('No relay list found for user', [
                    'pubkey' => substr($pubkeyHex, 0, 8) . '...'
                ]);
                return false;
            }

            // Persist relays to User entity for fast access during publishing
            $user->setRelays($relays);

            $this->logger->debug('Fetched and persisted relay list', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'read_count' => count($relays['read'] ?? []),
                'write_count' => count($relays['write'] ?? []),
            ]);

            return true;
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
