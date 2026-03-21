<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event as EventEntity;
use App\Entity\User;
use App\Enum\KindBundles;
use App\Enum\KindsEnum;
use App\Message\UpdateRelayListMessage;
use App\Repository\EventRepository;
use App\Service\ActiveIndexingService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserProfileService;
use App\Service\Nostr\UserRelayListService;
use App\Service\PublicationSubdomainService;
use App\Service\VanityNameService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event as NostrEvent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User Settings Controller
 *
 * Provides a unified settings page where authenticated users can:
 * - View/edit their profile (kind 0 metadata)
 * - See all their Nostr events used by the newsroom
 * - Manage subscriptions (vanity name, active indexing, publication subdomain)
 * - Access content management links
 */
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheItemPoolInterface $npubCache,
        private readonly VanityNameService $vanityNameService,
        private readonly ActiveIndexingService $activeIndexingService,
        private readonly PublicationSubdomainService $publicationSubdomainService,
        private readonly UserProfileService $userProfileService,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/settings', name: 'settings')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $npub = $user->getUserIdentifier();
        $pubkeyHex = NostrKeyUtil::npubToHex($npub);

        // Load all user context events from DB (for the Events tab)
        $events = $this->loadUserEvents($pubkeyHex);

        // If kind 0 is missing from the event table, try to backfill from
        // the npub cache so the Events tab shows it consistently.
        if (($events[KindsEnum::METADATA->value] ?? null) === null) {
            $backfilled = $this->backfillMetadataEvent($pubkeyHex);
            if ($backfilled !== null) {
                $events[KindsEnum::METADATA->value] = $backfilled;
            }
        }

        // Profile comes from the User entity (populated by UserMetadataSyncService on login)
        $profile = $this->buildProfileFromUser($user);

        // Raw kind 0 event — complete event for display, content extracted for JS merge
        $rawProfileEvent = $this->getRawProfileEvent($pubkeyHex, $events[KindsEnum::METADATA->value] ?? null);

        // Extract content and tags from raw event for JS merge (preserves unknown fields/tags)
        $existingContent = null;
        $existingTags = [];
        if ($rawProfileEvent !== null) {
            if (isset($rawProfileEvent['content'])) {
                $parsed = is_string($rawProfileEvent['content'])
                    ? json_decode($rawProfileEvent['content'], true)
                    : $rawProfileEvent['content'];
                if (is_array($parsed) && !empty($parsed)) {
                    $existingContent = $parsed;
                }
            }
            if (!empty($rawProfileEvent['tags'])) {
                $existingTags = $rawProfileEvent['tags'];
            }
        }

        // Subscriptions
        $vanityName = $this->vanityNameService->getByNpub($npub);
        $activeIndexing = $this->activeIndexingService->getSubscription($npub);
        $publicationSubdomain = $this->publicationSubdomainService->getByNpub($npub);

        return $this->render('settings/index.html.twig', [
            'npub' => $npub,
            'pubkeyHex' => $pubkeyHex,
            'profile' => $profile,
            'rawProfileEvent' => $rawProfileEvent,
            'existingContent' => $existingContent,
            'existingTags' => $existingTags,
            'events' => $events,
            'vanityName' => $vanityName,
            'activeIndexing' => $activeIndexing,
            'publicationSubdomain' => $publicationSubdomain,
        ]);
    }

    /**
     * API endpoint to publish a signed kind 0 metadata event.
     */
    #[Route('/api/settings/profile/publish', name: 'api_settings_profile_publish', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function publishProfile(
        Request $request,
        NostrClient $nostrClient,
        UserRelayListService $userRelayListService,
        UserProfileService $userProfileService,
        LoggerInterface $logger,
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['event'])) {
                return new JsonResponse(['error' => 'Invalid request data'], 400);
            }

            $signedEvent = $data['event'];

            // Validate required fields
            if (!isset($signedEvent['id'], $signedEvent['pubkey'], $signedEvent['created_at'],
                       $signedEvent['kind'], $signedEvent['sig'])) {
                return new JsonResponse(['error' => 'Missing required event fields'], 400);
            }

            // Validate kind (must be kind 0 - metadata)
            if ((int) $signedEvent['kind'] !== KindsEnum::METADATA->value) {
                return new JsonResponse(['error' => 'Invalid event kind, expected ' . KindsEnum::METADATA->value], 400);
            }

            // Convert to swentel Event object for verification and publishing
            $eventObj = new NostrEvent();
            $eventObj->setId($signedEvent['id']);
            $eventObj->setPublicKey($signedEvent['pubkey']);
            $eventObj->setCreatedAt($signedEvent['created_at']);
            $eventObj->setKind($signedEvent['kind']);
            $eventObj->setTags($signedEvent['tags'] ?? []);
            $eventObj->setContent($signedEvent['content'] ?? '');
            $eventObj->setSignature($signedEvent['sig']);

            // Verify signature
            if (!$eventObj->verify()) {
                return new JsonResponse(['error' => 'Event signature verification failed'], 400);
            }

            // Persist to local DB (persistInterestEvent is a public alias for persistEvent)
            $userProfileService->persistUserEvent((object) $signedEvent);

            // Update the User entity so settings page reflects changes immediately
            /** @var User $user */
            $user = $this->getUser();
            $this->updateUserEntityFromEvent($user, $signedEvent);

            // Update the npub cache with the new raw event so the raw JSON
            // section shows updated content on next page load
            $this->cacheRawProfileEvent($signedEvent);

            // Collect relays for publishing
            $pubkey = $signedEvent['pubkey'];
            $relays = $userRelayListService->getRelaysForPublishing($pubkey);

            $logger->info('Publishing profile metadata event', [
                'event_id' => $signedEvent['id'],
                'pubkey' => $pubkey,
                'relay_count' => count($relays),
            ]);

            // Publish to relays
            $relayResults = $nostrClient->publishEvent($eventObj, $relays);

            $successCount = 0;
            $failCount = 0;
            foreach ($relayResults as $relayUrl => $result) {
                $isSuccess = $result === true || (is_object($result) && isset($result->type) && $result->type === 'OK');
                if ($isSuccess) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }

            return new JsonResponse([
                'success' => $successCount > 0,
                'relays_success' => $successCount,
                'relays_failed' => $failCount,
            ]);
        } catch (\Throwable $e) {
            $logger->error('Profile publish error', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Publishing failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API endpoint to publish a generic signed event (for media follows, etc.)
     */
    #[Route('/api/settings/event/publish', name: 'api_settings_event_publish', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function publishEvent(
        Request $request,
        NostrClient $nostrClient,
        UserRelayListService $userRelayListService,
        UserProfileService $userProfileService,
        LoggerInterface $logger,
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['event'])) {
                return new JsonResponse(['error' => 'Invalid request data'], 400);
            }

            $signedEvent = $data['event'];

            if (!isset($signedEvent['id'], $signedEvent['pubkey'], $signedEvent['created_at'],
                       $signedEvent['kind'], $signedEvent['sig'])) {
                return new JsonResponse(['error' => 'Missing required event fields'], 400);
            }

            // Allow only user-context kinds
            $allowedKinds = KindBundles::USER_CONTEXT;
            if (!in_array((int) $signedEvent['kind'], $allowedKinds, true)) {
                return new JsonResponse(['error' => 'Event kind not allowed'], 400);
            }

            $eventObj = new NostrEvent();
            $eventObj->setId($signedEvent['id']);
            $eventObj->setPublicKey($signedEvent['pubkey']);
            $eventObj->setCreatedAt($signedEvent['created_at']);
            $eventObj->setKind($signedEvent['kind']);
            $eventObj->setTags($signedEvent['tags'] ?? []);
            $eventObj->setContent($signedEvent['content'] ?? '');
            $eventObj->setSignature($signedEvent['sig']);

            if (!$eventObj->verify()) {
                return new JsonResponse(['error' => 'Event signature verification failed'], 400);
            }

            $userProfileService->persistUserEvent((object) $signedEvent);

            $pubkey = $signedEvent['pubkey'];
            $relays = $userRelayListService->getRelaysForPublishing($pubkey);
            $relayResults = $nostrClient->publishEvent($eventObj, $relays);

            $successCount = 0;
            foreach ($relayResults as $result) {
                if ($result === true || (is_object($result) && isset($result->type) && $result->type === 'OK')) {
                    $successCount++;
                }
            }

            return new JsonResponse([
                'success' => $successCount > 0,
                'relays_success' => $successCount,
            ]);
        } catch (\Throwable $e) {
            $logger->error('Event publish error', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Publishing failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API endpoint to trigger a full relay sync (same pipeline as login).
     *
     * Dispatches UpdateRelayListMessage which cascades into SyncUserEventsMessage,
     * batch-fetching all user events from NIP-65 relays asynchronously.
     */
    #[Route('/api/settings/sync', name: 'api_settings_sync', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function syncFromRelays(
        MessageBusInterface $messageBus,
    ): JsonResponse {
        try {
            /** @var User $user */
            $user = $this->getUser();
            $npub = $user->getUserIdentifier();
            $pubkeyHex = NostrKeyUtil::npubToHex($npub);

            $messageBus->dispatch(new UpdateRelayListMessage($pubkeyHex, fullSync: true));

            $this->logger->info('Settings: dispatched relay sync for user', [
                'npub' => substr($npub, 0, 16) . '...',
            ]);

            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('Settings: relay sync dispatch failed', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Sync failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Load all user context events from the local DB.
     *
     * @return array<int, EventEntity|null> kind => latest Event entity
     */
    private function loadUserEvents(string $pubkeyHex): array
    {
        $kinds = KindBundles::USER_CONTEXT;
        $events = [];

        foreach ($kinds as $kind) {
            $events[$kind] = $this->eventRepository->findLatestByPubkeyAndKind($pubkeyHex, $kind);
        }

        // Also load follow packs (kind 39089) — parameterized replaceable, can have multiple
        $followPacks = $this->eventRepository->createQueryBuilder('e')
            ->where('e.pubkey = :pubkey')
            ->andWhere('e.kind = :kind')
            ->setParameter('pubkey', $pubkeyHex)
            ->setParameter('kind', KindsEnum::FOLLOW_PACK->value)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $events['follow_packs'] = $followPacks;

        return $events;
    }

    /**
     * Update User entity fields from a signed kind 0 event.
     *
     * Called after publishing so the profile page reflects changes immediately
     * without waiting for the next login sync cycle.
     */
    private function updateUserEntityFromEvent(User $user, array $signedEvent): void
    {
        // Extract from tags first (primary), then content JSON (fallback)
        $fields = [];
        foreach ($signedEvent['tags'] ?? [] as $tag) {
            if (is_array($tag) && count($tag) >= 2) {
                $fields[$tag[0]] = $tag[1];
            }
        }

        $content = json_decode($signedEvent['content'] ?? '{}', true);
        if (is_array($content)) {
            // Tags take priority — only fill in fields not already set from tags
            $fields = array_merge($content, $fields);
        }

        if (isset($fields['display_name'])) {
            $user->setDisplayName($fields['display_name']);
        }
        if (isset($fields['name'])) {
            $user->setName($fields['name']);
        }
        if (isset($fields['about'])) {
            $user->setAbout($fields['about']);
        }
        if (isset($fields['picture'])) {
            $user->setPicture($fields['picture']);
        }
        if (isset($fields['banner'])) {
            $user->setBanner($fields['banner']);
        }
        if (isset($fields['nip05'])) {
            $user->setNip05($fields['nip05']);
        }
        if (isset($fields['lud16'])) {
            $user->setLud16($fields['lud16']);
        }
        if (isset($fields['website'])) {
            $user->setWebsite($fields['website']);
        }

        $user->setLastMetadataRefresh(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * Backfill kind 0 into the event table by fetching from relays.
     *
     * Called when the Events tab would show kind 0 as "not found" even though
     * the User entity has profile data. This happens when the user visits
     * settings before the async pipeline has persisted the event.
     *
     * Fetches directly from the local strfry relay (+ fallback to public relays)
     * via UserProfileService, which is synchronous and reliable — no dependency
     * on Messenger workers or subscription daemons.
     *
     * Returns the persisted Event entity, or null if no kind 0 is found.
     */
    private function backfillMetadataEvent(string $pubkeyHex): ?EventEntity
    {
        try {
            $rawEvent = $this->userProfileService->getMetadata($pubkeyHex);

            // Persist to event table (handles duplicates, replaceable semantics)
            $this->userProfileService->persistUserEvent($rawEvent);

            // Re-read from event table to return the entity
            return $this->eventRepository->findLatestByPubkeyAndKind($pubkeyHex, KindsEnum::METADATA->value);
        } catch (\Throwable $e) {
            $this->logger->debug('Settings: could not backfill kind 0 from relay', [
                'pubkey' => substr($pubkeyHex, 0, 16) . '...',
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get the raw kind 0 event as a complete array.
     *
     * Primary source: event table (persistent).
     * Fallback: npub cache (may have the raw event before the worker persists it).
     *
     * Returns the full Nostr event (id, pubkey, kind, created_at, content,
     * tags, sig) — shown as raw JSON in the template for transparency.
     */
    private function getRawProfileEvent(string $pubkeyHex, ?EventEntity $metadataEvent): ?array
    {
        // 1. Event table (reliable, persistent)
        if ($metadataEvent !== null) {
            return [
                'id'         => $metadataEvent->getId(),
                'pubkey'     => $metadataEvent->getPubkey(),
                'kind'       => $metadataEvent->getKind(),
                'created_at' => $metadataEvent->getCreatedAt(),
                'content'    => $metadataEvent->getContent(),
                'tags'       => $metadataEvent->getTags(),
                'sig'        => $metadataEvent->getSig(),
            ];
        }

        // 2. Npub cache fallback (raw relay event)
        try {
            $item = $this->npubCache->getItem('pubkey_meta_' . $pubkeyHex);
            if ($item->isHit()) {
                $cached = $item->get();
                if (is_object($cached) && isset($cached->id, $cached->kind)) {
                    return [
                        'id'         => $cached->id ?? '',
                        'pubkey'     => $cached->pubkey ?? $pubkeyHex,
                        'kind'       => (int) ($cached->kind ?? 0),
                        'created_at' => (int) ($cached->created_at ?? 0),
                        'content'    => $cached->content ?? '',
                        'tags'       => is_array($cached->tags ?? null) ? $cached->tags : [],
                        'sig'        => $cached->sig ?? '',
                    ];
                }
            }
        } catch (\Throwable) {
            // Non-critical
        }

        return null;
    }

    /**
     * Update the npub cache with a freshly published kind 0 event.
     *
     * Ensures the raw JSON section shows the new content on next page load
     * without waiting for a relay round-trip.
     */
    private function cacheRawProfileEvent(array $signedEvent): void
    {
        try {
            $pubkey = $signedEvent['pubkey'] ?? '';
            if ($pubkey === '') {
                return;
            }

            $cacheKey = 'pubkey_meta_' . $pubkey;
            $item = $this->npubCache->getItem($cacheKey);
            $item->set((object) $signedEvent);
            $item->expiresAfter(84000); // ~24 h
            $this->npubCache->save($item);
        } catch (\Throwable $e) {
            $this->logger->debug('Could not update npub cache after profile publish', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the profile array from the User entity.
     *
     * The User entity is the canonical source for profile metadata — it is
     * populated by UserMetadataSyncService from the Redis metadata cache on
     * every login. Kind 0 events may or may not exist in the event table;
     * the User entity is always up to date.
     */
    private function buildProfileFromUser(User $user): array
    {
        // getName() falls back to the npub — don't pre-populate the form with it
        $name = $user->getName();
        if ($name === $user->getUserIdentifier()) {
            $name = '';
        }

        return [
            'display_name' => $user->getDisplayName() ?? '',
            'name'         => $name ?? '',
            'about'        => $user->getAbout() ?? '',
            'picture'      => $user->getPicture() ?? '',
            'banner'       => $user->getBanner() ?? '',
            'nip05'        => $user->getNip05() ?? '',
            'lud16'        => $user->getLud16() ?? '',
            'website'      => $user->getWebsite() ?? '',
        ];
    }
}

