<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\SyncUserEventsMessage;
use App\Repository\EventRepository;
use App\Service\Nostr\UserRelayListService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

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
     * Load the user's bookmark events from the local DB only.
     *
     * If nothing is found, dispatch an async SyncUserEventsMessage so the
     * Messenger worker fetches and persists bookmarks in the background.
     * Returns a [bookmarks, syncing] tuple — callers pass 'syncing' to the
     * template so the UI can show a "syncing…" banner and auto-refresh.
     *
     * The synchronous relay fallback that was here previously caused request
     * timeouts: a blocking REQ to external relays can take 5–30 seconds and
     * is completely unnecessary because SyncUserEventsHandler already fetches
     * all bookmark kinds on login.
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

        $syncing = false;

        if (empty($events)) {
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
            array_map(fn(Event $e) => $this->parseBookmarkEvent($e), $events),
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

