<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrClient;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
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
        NostrClient $nostrClient,
        EventRepository $eventRepository,
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('home');
        }

        $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        $bookmarks = $this->loadBookmarks($pubkey, $em, $nostrClient);

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
        ]);
    }

    /**
     * Load the user's bookmark events – all bookmark-related kinds in one query.
     *
     * 1. Try the local DB first.
     * 2. If not found, fetch synchronously from relays via NostrClient.
     *
     * @return object[]
     */
    private function loadBookmarks(string $pubkey, EntityManagerInterface $em, NostrClient $nostrClient): array
    {
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

        // Fallback: sync fetch from relays
        if (empty($events)) {
            $events = $this->fetchFromRelays($pubkey, $nostrClient, $em);
        }

        return array_map(fn(Event $e) => $this->parseBookmarkEvent($e), $events);
    }

    /**
     * Fetch bookmark events directly from relays and persist them.
     *
     * @return Event[]
     */
    private function fetchFromRelays(string $pubkey, NostrClient $nostrClient, EntityManagerInterface $em): array
    {
        try {
            $relayEvents = $nostrClient->fetchBookmarks($pubkey);

            if (empty($relayEvents)) {
                return [];
            }

            $persisted = [];
            foreach ($relayEvents as $raw) {
                $event = new Event();
                $event->setId($raw->id);
                $event->setEventId($raw->id);
                $event->setPubkey($raw->pubkey);
                $event->setKind($raw->kind);
                $event->setContent($raw->content ?? '');
                $event->setTags($raw->tags ?? []);
                $event->setCreatedAt($raw->created_at);
                $event->setSig($raw->sig ?? '');

                $em->persist($event);
                $persisted[] = $event;
            }

            $em->flush();

            return $persisted;
        } catch (\Throwable $e) {
            $this->logger->warning('📚 Failed to fetch bookmarks from relays', [
                'pubkey' => $pubkey,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
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

