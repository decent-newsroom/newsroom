<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\SyncUserEventsMessage;
use App\Repository\EventRepository;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use swentel\nostr\Event\Event as NostrEvent;

class BookmarksController extends AbstractController
{
    private const BOOKMARK_KINDS = [
        KindsEnum::BOOKMARKS,         // 10003
        KindsEnum::BOOKMARK_SETS,     // 30003
        KindsEnum::CURATION_SET,      // 30004
        KindsEnum::CURATION_VIDEOS,   // 30005
        KindsEnum::CURATION_PICTURES, // 30006
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/my-bookmarks', name: 'my_bookmarks')]
    public function index(
        EntityManagerInterface $em,
        EventRepository $eventRepository,
        UserRelayListService $userRelayListService,
        MessageBusInterface $bus,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('home');
        }

        $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        [$bookmarks, $syncing] = $this->loadBookmarks($pubkey, $em, $userRelayListService, $bus);

        // Batch-resolve all 'e'-type bookmark items from the DB in one query
        $allEventIds = [];
        foreach ($bookmarks as $bookmark) {
            foreach ($bookmark->items as $item) {
                if ($item['type'] === 'e' && !empty($item['value'])) {
                    $allEventIds[] = $item['value'];
                }
            }
        }

        $resolvedEvents = [];
        if (!empty($allEventIds)) {
            $resolvedEvents = $eventRepository->findByIds(array_unique($allEventIds));
        }

        return $this->render('pages/my-bookmarks.html.twig', [
            'bookmarks' => $bookmarks,
            'resolvedEvents' => $resolvedEvents,
            'syncing' => $syncing,
        ]);
    }

    /**
     * Return the current user's kind 10003 bookmark tags as JSON.
     * Used by the Stimulus bookmark controller on article pages.
     */
    #[Route('/api/bookmarks/current', name: 'api_bookmarks_current', methods: ['GET'])]
    public function currentBookmarks(
        EntityManagerInterface $em,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());

        $event = $em->getRepository(Event::class)
            ->createQueryBuilder('e')
            ->where('e.pubkey = :pubkey')
            ->andWhere('e.kind = :kind')
            ->setParameter('pubkey', $pubkey)
            ->setParameter('kind', KindsEnum::BOOKMARKS->value)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$event) {
            return new JsonResponse([
                'tags' => [],
                'eventExists' => false,
            ]);
        }

        return new JsonResponse([
            'tags' => $event->getTags(),
            'eventExists' => true,
        ]);
    }

    /**
     * Publish a signed kind 10003 bookmark event.
     * Persists locally via GenericEventProjector (which handles replaceable-event
     * deduplication) and broadcasts to the user's relays.
     */
    #[Route('/api/bookmarks/publish', name: 'api_bookmarks_publish', methods: ['POST'])]
    public function publishBookmarks(
        Request $request,
        NostrClient $nostrClient,
        UserRelayListService $userRelayListService,
        GenericEventProjector $eventProjector,
        LoggerInterface $logger,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        try {
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['event'])) {
                return new JsonResponse(['error' => 'Invalid request data'], 400);
            }

            $signedEvent = $data['event'];

            // Validate required fields
            if (!isset($signedEvent['id'], $signedEvent['pubkey'], $signedEvent['created_at'],
                       $signedEvent['kind'], $signedEvent['tags'], $signedEvent['sig'])) {
                return new JsonResponse(['error' => 'Missing required event fields'], 400);
            }

            // Validate kind
            if ((int) $signedEvent['kind'] !== KindsEnum::BOOKMARKS->value) {
                return new JsonResponse(['error' => 'Invalid event kind, expected ' . KindsEnum::BOOKMARKS->value], 400);
            }

            // Convert to swentel Event object for signature verification
            $eventObj = new NostrEvent();
            $eventObj->setId($signedEvent['id']);
            $eventObj->setPublicKey($signedEvent['pubkey']);
            $eventObj->setCreatedAt($signedEvent['created_at']);
            $eventObj->setKind($signedEvent['kind']);
            $eventObj->setTags($signedEvent['tags']);
            $eventObj->setContent($signedEvent['content'] ?? '');
            $eventObj->setSignature($signedEvent['sig']);

            // Verify signature
            if (!$eventObj->verify()) {
                return new JsonResponse(['error' => 'Event signature verification failed'], 400);
            }

            // Persist via GenericEventProjector — handles replaceable event dedup (NIP-01)
            $rawEvent = (object) [
                'id' => $signedEvent['id'],
                'pubkey' => $signedEvent['pubkey'],
                'created_at' => (int) $signedEvent['created_at'],
                'kind' => (int) $signedEvent['kind'],
                'tags' => $signedEvent['tags'],
                'content' => $signedEvent['content'] ?? '',
                'sig' => $signedEvent['sig'],
            ];
            $eventProjector->projectEventFromNostrEvent($rawEvent, 'local');

            // Publish to user's relays
            $pubkey = $signedEvent['pubkey'];
            $relays = $userRelayListService->getRelaysForPublishing($pubkey);

            $logger->info('Publishing bookmarks event', [
                'event_id' => $signedEvent['id'],
                'pubkey' => substr($pubkey, 0, 8) . '...',
                'tag_count' => count($signedEvent['tags']),
                'relay_count' => count($relays),
            ]);

            $relayResults = $nostrClient->publishEvent($eventObj, $relays);

            // Transform results
            $successCount = 0;
            $failCount = 0;
            $relayStatuses = [];

            foreach ($relayResults as $relayUrl => $result) {
                $isSuccess = $result === true || (is_object($result) && isset($result->type) && $result->type === 'OK');
                if ($isSuccess) {
                    $successCount++;
                } else {
                    $failCount++;
                }
                $relayStatuses[] = [
                    'relay' => $relayUrl,
                    'success' => $isSuccess,
                ];
            }

            return new JsonResponse([
                'success' => true,
                'event_id' => $signedEvent['id'],
                'relays' => [
                    'success' => $successCount,
                    'failed' => $failCount,
                    'details' => $relayStatuses,
                ],
            ]);
        } catch (\Throwable $e) {
            $logger->error('Failed to publish bookmarks event', [
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Publishing failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Load the user's bookmark events from the local DB only.
     *
     * If nothing is found, dispatch an async SyncUserEventsMessage so the
     * Messenger worker fetches and persists bookmarks in the background.
     * Returns a [bookmarks, syncing] tuple — callers pass 'syncing' to the
     * template so the UI can show a "syncing…" banner and auto-refresh.
     *
     * Deduplicates kind 10003 events: since kind 10003 is replaceable
     * (NIP-01, range 10000–19999), only the latest event per pubkey is kept.
     *
     * @return array{0: object[], 1: bool}
     */
    private function loadBookmarks(
        string $pubkey,
        EntityManagerInterface $em,
        UserRelayListService $userRelayListService,
        MessageBusInterface $bus,
    ): array {
        $repo = $em->getRepository(Event::class);
        $kindValues = array_map(fn(KindsEnum $k) => $k->value, self::BOOKMARK_KINDS);

        $events = $repo->createQueryBuilder('e')
            ->where('e.pubkey = :pubkey')
            ->andWhere('e.kind IN (:kinds)')
            ->setParameter('pubkey', $pubkey)
            ->setParameter('kinds', $kindValues)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        // Deduplicate replaceable kinds (10003): keep only the newest per pubkey+kind.
        // Parameterized replaceable kinds (30003–30006) are deduped by pubkey+kind+dTag.
        $seen = [];
        $staleIds = [];
        $deduped = [];

        foreach ($events as $event) {
            $kind = $event->getKind();

            if ($kind >= 10000 && $kind <= 19999) {
                // Replaceable: one per pubkey+kind
                $key = $kind . ':' . $event->getPubkey();
                if (isset($seen[$key])) {
                    $staleIds[] = $event->getId();
                    continue;
                }
                $seen[$key] = true;
            } elseif ($kind >= 30000 && $kind <= 39999) {
                // Parameterized replaceable: one per pubkey+kind+dTag
                $key = $kind . ':' . $event->getPubkey() . ':' . ($event->getDTag() ?? '');
                if (isset($seen[$key])) {
                    $staleIds[] = $event->getId();
                    continue;
                }
                $seen[$key] = true;
            }

            $deduped[] = $event;
        }

        // Clean up stale duplicates from the DB in the background
        if (!empty($staleIds)) {
            try {
                $em->createQueryBuilder()
                    ->delete(Event::class, 'e')
                    ->where('e.id IN (:ids)')
                    ->setParameter('ids', $staleIds)
                    ->getQuery()
                    ->execute();

                $this->logger->info('BookmarksController: cleaned up stale duplicate bookmark events', [
                    'pubkey' => substr($pubkey, 0, 8) . '...',
                    'deleted_count' => count($staleIds),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('BookmarksController: failed to clean up stale bookmarks', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $syncing = false;

        if (empty($deduped)) {
            // Nothing in DB yet — dispatch async sync and tell the template
            // to show a "syncing" state with an auto-refresh.
            $syncing = true;
            try {
                // getRelayList() resolves from cache/DB only (no network call on the hot path).
                $relayList = $userRelayListService->getRelayList($pubkey);
                $relayUrls = $relayList['read'] ?? $relayList['all'] ?? [];

                $bus->dispatch(new SyncUserEventsMessage($pubkey, $relayUrls));

                $this->logger->info('BookmarksController: dispatched async sync (no bookmarks in DB yet)', [
                    'pubkey' => substr($pubkey, 0, 8) . '...',
                    'relay_count' => count($relayUrls),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('BookmarksController: failed to dispatch sync', [
                    'pubkey' => substr($pubkey, 0, 8) . '...',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            array_map(fn(Event $e) => $this->parseBookmarkEvent($e), $deduped),
            $syncing,
        ];
    }

    /**
     * Parse an Event entity into a display-friendly bookmark object.
     */
    private function parseBookmarkEvent(Event $event): object
    {
        $identifier = null;
        $title = null;
        $summary = null;
        $image = null;
        $items = [];

        foreach ($event->getTags() as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            switch ($tag[0]) {
                case 'd':
                    $identifier = $tag[1] ?? null;
                    break;
                case 'title':
                    $title = $tag[1] ?? null;
                    break;
                case 'summary':
                    $summary = $tag[1] ?? null;
                    break;
                case 'image':
                    $image = $tag[1] ?? null;
                    break;
                case 'e':
                case 'a':
                case 'p':
                case 't':
                    $items[] = [
                        'type' => $tag[0],
                        'value' => $tag[1] ?? null,
                        'relay' => $tag[2] ?? null,
                    ];
                    break;
            }
        }

        $listType = match ($event->getKind()) {
            KindsEnum::BOOKMARKS->value => 'Bookmarks',
            KindsEnum::BOOKMARK_SETS->value => 'Bookmark Set',
            KindsEnum::CURATION_SET->value => 'Curation Set (Articles/Notes)',
            KindsEnum::CURATION_VIDEOS->value => 'Curation Set (Videos)',
            KindsEnum::CURATION_PICTURES->value => 'Curation Set (Pictures)',
            default => 'Unknown List',
        };

        return (object) [
            'id' => $event->getId(),
            'kind' => $event->getKind(),
            'listType' => $listType,
            'identifier' => $identifier,
            'title' => $title,
            'description' => $summary,
            'image' => $image,
            'items' => $items,
            'createdAt' => $event->getCreatedAt(),
        ];
    }
}

