<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event as EventEntity;
use App\Enum\KindBundles;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\ActiveIndexingService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserProfileService;
use App\Service\Nostr\UserRelayListService;
use App\Service\PublicationSubdomainService;
use App\Service\VanityNameService;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event as NostrEvent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        private readonly VanityNameService $vanityNameService,
        private readonly ActiveIndexingService $activeIndexingService,
        private readonly PublicationSubdomainService $publicationSubdomainService,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/settings', name: 'settings')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $npub = $this->getUser()->getUserIdentifier();
        $pubkeyHex = NostrKeyUtil::npubToHex($npub);

        // Load all user context events from DB
        $events = $this->loadUserEvents($pubkeyHex);

        // Parse profile metadata from kind 0 event
        $profile = $this->parseProfileFromEvent($events[KindsEnum::METADATA->value] ?? null);

        // Subscriptions
        $vanityName = $this->vanityNameService->getByNpub($npub);
        $activeIndexing = $this->activeIndexingService->getSubscription($npub);
        $publicationSubdomain = $this->publicationSubdomainService->getByNpub($npub);

        return $this->render('settings/index.html.twig', [
            'npub' => $npub,
            'pubkeyHex' => $pubkeyHex,
            'profile' => $profile,
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
     * Parse profile metadata from a kind 0 event.
     */
    private function parseProfileFromEvent(?EventEntity $event): array
    {
        $profile = [
            'display_name' => '',
            'name' => '',
            'about' => '',
            'picture' => '',
            'banner' => '',
            'nip05' => '',
            'lud16' => '',
            'website' => '',
        ];

        if ($event === null) {
            return $profile;
        }

        // Try content JSON first (legacy format still common)
        $content = json_decode($event->getContent(), true);
        if (is_array($content)) {
            $profile = array_merge($profile, array_intersect_key($content, $profile));
        }

        // Override with tags if present (newer format)
        foreach ($event->getTags() as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }
            $key = $tag[0];
            $value = $tag[1];
            if (array_key_exists($key, $profile)) {
                $profile[$key] = $value;
            }
        }

        return $profile;
    }
}

