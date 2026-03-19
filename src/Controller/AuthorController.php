<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Event;
use App\Entity\Magazine;
use App\Enum\KindsEnum;
use App\Message\RevalidateProfileCacheMessage;
use App\Message\FetchMissingCurationMediaMessage;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Repository\VisitRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrLinkParser;
use App\Service\Nostr\NostrClient;
use App\Service\Search\ArticleSearchInterface;
use App\Service\VanityNameService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use swentel\nostr\Nip19\Nip19Helper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

class AuthorController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly NostrLinkParser $nostrLinkParser,
        private readonly VanityNameService $vanityNameService,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    /**
     * Resolve vanity name to npub, or redirect npub to vanity if exists
     * @return string|Response|array Returns npub string, Response redirect, or array with npub and vanity info
     */
    private function resolveVanityOrRedirect(?string $npub, ?string $vanity, string $routeName, array $params = []): string|Response|array
    {
        if ($vanity !== null) {
            // Vanity name provided, resolve it to npub and return both
            $vanityObj = $this->vanityNameService->getActiveByVanityName($vanity);
            if ($vanityObj === null) {
                throw $this->createNotFoundException('Profile not found.');
            }
            return [
                'npub' => $vanityObj->getNpub(),
                'vanity' => $vanity,
                'useVanity' => true
            ];
        }

        if ($npub !== null) {
            // Npub provided, check if it has a vanity name and redirect
            $vanityObj = $this->vanityNameService->getActiveByNpub($npub);
            if ($vanityObj !== null) {
                return $this->redirectToRoute($routeName, array_merge(['vanity' => $vanityObj->getVanityName()], $params), 301);
            }
            return [
                'npub' => $npub,
                'vanity' => null,
                'useVanity' => false
            ];
        }

        throw $this->createNotFoundException('Profile not found.');
    }

    /**
     * Reading List Index
     */
    #[Route('/{vanity}/lists', name: 'author-vanity-reading-lists')]
    #[Route('/p/{npub}/lists', name: 'author-reading-lists', requirements: ['npub' => '^npub1.*'])]
    public function readingLists(string $npub = null, string $vanity = null,
                                EntityManagerInterface $em,
                                NostrKeyUtil $keyUtil,
                                LoggerInterface $logger): Response
    {
        $resolved = $this->resolveVanityOrRedirect($npub, $vanity, 'author-vanity-reading-lists');
        if ($resolved instanceof Response) {
            return $resolved;
        }

        $npub = $resolved['npub'];

        // Convert npub to hex pubkey
        $pubkey = $keyUtil->npubToHex($npub);
        $logger->info(sprintf('Reading list: pubkey=%s', $pubkey));
        // Find reading lists by pubkey, kind 30040 directly from database
        $repo = $em->getRepository(Event::class);
        $lists = $repo->findBy(['pubkey' => $pubkey, 'kind' => KindsEnum::PUBLICATION_INDEX], ['created_at' => 'DESC']);
        // Filter to ensure they have a 'type:reading-list' tag
        $filteredLists     = [];
        $seenSlugs        = [];
        foreach ($lists as $ev) {
            if (!$ev instanceof Event) continue;
            $tags = $ev->getTags();
            $isReadingList = false;
            $title = null; $slug = null; $summary = null;
            foreach ($tags as $t) {
                if (is_array($t)) {
                    if (($t[0] ?? null) === 'type' && ($t[1] ?? null) === 'reading-list') { $isReadingList = true; }
                    if (($t[0] ?? null) === 'title') { $title = (string)$t[1]; }
                    if (($t[0] ?? null) === 'summary') { $summary = (string)$t[1]; }
                    if (($t[0] ?? null) === 'd') { $slug = (string)$t[1]; }
                }
            }
            if ($isReadingList) {
                // Collapse by slug: keep only newest per slug
                $keySlug = $slug ?: ('__no_slug__:' . $ev->getId());
                if (isset($seenSlugs[$slug ?? $keySlug])) {
                    continue;
                }
                $seenSlugs[$slug ?? $keySlug] = true;
                $filteredLists[] = $ev;
            }
        }

        return $this->render('profile/author-lists.html.twig', [
            'lists' => $filteredLists,
            'npub' => $npub,
        ]);
    }

    /**
     * List
     * @throws Exception
     */
    #[Route('/{vanity}/list/{slug}', name: 'author-vanity-reading-list')]
    #[Route('/p/{npub}/list/{slug}', name: 'reading-list', requirements: ['npub' => '^npub1.*'])]
    public function readingList(string $slug, string $npub = null, string $vanity = null,
                                EntityManagerInterface $em,
                                NostrKeyUtil $keyUtil,
                                LoggerInterface $logger): Response
    {
        $resolved = $this->resolveVanityOrRedirect($npub, $vanity, 'author-vanity-reading-list', ['slug' => $slug]);
        if ($resolved instanceof Response) {
            return $resolved;
        }

        $npub = $resolved['npub'];

        // Convert npub to hex pubkey
        $pubkey = $keyUtil->npubToHex($npub);
        $logger->info(sprintf('Reading list: pubkey=%s, slug=%s', $pubkey, $slug));

        $cacheKey = 'author_reading_list_' . md5($pubkey . ':' . $slug);
        try {
            $cacheItem = $this->cache->getItem($cacheKey);
            if ($cacheItem->isHit()) {
                $cached = $cacheItem->get();
                if (is_array($cached) && isset($cached['list'], $cached['articles'])) {
                    return $this->render('pages/list.html.twig', [
                        'list' => $cached['list'],
                        'articles' => $cached['articles'],
                    ]);
                }
            }
        } catch (\Throwable) {
            // Ignore cache failures
        }

        // Find reading list by pubkey+slug, kind 30040 directly from database
        $repo = $em->getRepository(Event::class);
        $lists = $repo->findBy(['pubkey' => $pubkey, 'kind' => KindsEnum::PUBLICATION_INDEX], ['created_at' => 'DESC']);
        // Filter by slug
        $list = null;
        foreach ($lists as $ev) {
            if (!$ev instanceof Event) continue;

            $eventSlug = $ev->getSlug();

            if ($eventSlug === $slug) {
                $list = $ev;
                break; // Found the latest one
            }
        }

        if (!$list) {
            throw $this->createNotFoundException('Reading list not found');
        }

        // fetch articles listed in the list's a tags
        $coordinates = []; // Store full coordinates (kind:author:slug)
        // Extract category metadata and article coordinates
        foreach ($list->getTags() as $tag) {
            if ($tag[0] === 'a') {
                $coordinates[] = $tag[1]; // Store the full coordinate
            }
        }

        $articles = [];
        if (count($coordinates) > 0) {
            $articleRepo = $em->getRepository(Article::class);

            // Batch load by (pubkey, slug) to avoid N+1 queries.
            // We still dedupe by latest createdAt per slug after fetching.
            $want = [];
            foreach ($coordinates as $coord) {
                $parts = explode(':', $coord, 3);
                if (count($parts) === 3) {
                    [, $author, $articleSlug] = $parts;
                    $want[] = ['pubkey' => $author, 'slug' => $articleSlug, 'coord' => $coord];
                }
            }

            $foundMap = [];
            if (!empty($want)) {
                $pubkeys = array_values(array_unique(array_map(fn($w) => $w['pubkey'], $want)));
                $slugs = array_values(array_unique(array_map(fn($w) => $w['slug'], $want)));

                // Fetch candidates in one query; we will select best match per (pubkey,slug).
                $candidates = $articleRepo->createQueryBuilder('a')
                    ->where('a.pubkey IN (:pubkeys)')
                    ->andWhere('a.slug IN (:slugs)')
                    ->setParameter('pubkeys', $pubkeys)
                    ->setParameter('slugs', $slugs)
                    ->orderBy('a.createdAt', 'DESC')
                    ->getQuery()
                    ->getResult();

                foreach ($candidates as $cand) {
                    if (!$cand instanceof Article) {
                        continue;
                    }
                    $k = $cand->getPubkey() . ':' . $cand->getSlug();
                    if (!isset($foundMap[$k])) {
                        $foundMap[$k] = $cand;
                    }
                }
            }

            foreach ($coordinates as $coord) {
                $parts = explode(':', $coord, 3);
                if (count($parts) !== 3) {
                    continue;
                }
                [$kind, $author, $articleSlug] = $parts;
                $k = $author . ':' . $articleSlug;
                if (isset($foundMap[$k])) {
                    $articles[] = $foundMap[$k];
                    continue;
                }

                // If not found, add placeholder
                $articles[] = (object)[
                    'pubkey' => $author,
                    'slug' => $articleSlug,
                    'coordinate' => $coord,
                    'kind' => (int) $kind,
                    'title' => null,
                ];
            }
        }

        $logger->info('Reading list loaded', [
            'slug' => $slug,
            'total_coordinates' => count($coordinates),
            'total_articles' => count($articles)
        ]);

        try {
            $cacheItem = $this->cache->getItem($cacheKey);
            $cacheItem->set(['list' => $list, 'articles' => $articles]);
            $cacheItem->expiresAfter(120);
            $this->cache->save($cacheItem);
        } catch (\Throwable) {
        }

        return $this->render('pages/list.html.twig', [
            'list' => $list,
            'articles' => $articles,
        ]);
    }

    /**
     * Curation Set (kinds 30004, 30005, 30006)
     * Displays curated content based on kind type
     * @throws Exception
     */
    #[Route('/{vanity}/curation/{kind}/{slug}', name: 'author-vanity-curation-set', requirements: ['kind' => '30004|30005|30006'])]
    #[Route('/p/{npub}/curation/{kind}/{slug}', name: 'curation-set', requirements: ['npub' => '^npub1.*', 'kind' => '30004|30005|30006'])]
    public function curationSet(int $kind, string $slug, string $npub = null, string $vanity = null,
                                EntityManagerInterface $em,
                                MessageBusInterface $messageBus,
                                NostrClient $nostrClient,
                                GenericEventProjector $genericEventProjector,
                                LoggerInterface $logger): Response
    {
        $resolved = $this->resolveVanityOrRedirect($npub, $vanity, 'author-vanity-curation-set', ['kind' => $kind, 'slug' => $slug]);
        if ($resolved instanceof Response) {
            return $resolved;
        }

        $npub = $resolved['npub'];

        $logger->info(sprintf('Curation set: npub=%s, kind=%d, slug=%s', $npub, $kind, $slug));

        // Convert npub to hex pubkey
        try {
            $keys = new Key();
            $pubkeyHex = $keys->convertToHex($npub);
        } catch (\Exception $e) {
            throw $this->createNotFoundException('Invalid npub');
        }

        // Validate kind is a curation type
        $validKinds = [
            KindsEnum::CURATION_SET->value,       // 30004
            KindsEnum::CURATION_VIDEOS->value,    // 30005
            KindsEnum::CURATION_PICTURES->value   // 30006
        ];

        if (!in_array($kind, $validKinds)) {
            throw $this->createNotFoundException('Invalid curation type');
        }

        // Find curation set by kind, pubkey and slug
        $repo = $em->getRepository(Event::class);
        $events = $repo->createQueryBuilder('e')
            ->where('e.kind = :kind')
            ->andWhere('e.pubkey = :pubkey')
            ->setParameter('kind', $kind)
            ->setParameter('pubkey', $pubkeyHex)
            ->orderBy('e.created_at', 'DESC')
            ->getQuery()
            ->getResult();

        // Filter by slug
        $curation = null;
        foreach ($events as $ev) {
            if (!$ev instanceof Event) continue;
            if ($ev->getSlug() === $slug) {
                $curation = $ev;
                break;
            }
        }

        if (!$curation) {
            $logger->info('Curation set not found locally, trying relay fallback', [
                'kind' => $kind,
                'pubkey' => $pubkeyHex,
                'slug' => $slug,
            ]);

            $remoteEvent = $nostrClient->getEventByNaddr([
                'kind' => $kind,
                'pubkey' => $pubkeyHex,
                'identifier' => $slug,
                'relays' => [],
            ]);

            if ($remoteEvent !== null) {
                try {
                    $curation = $genericEventProjector->projectEventFromNostrEvent($remoteEvent, 'curation-route-fallback');
                } catch (\Throwable $e) {
                    $logger->warning('Failed to persist curation set fetched from relay fallback', [
                        'kind' => $kind,
                        'pubkey' => $pubkeyHex,
                        'slug' => $slug,
                        'error' => $e->getMessage(),
                    ]);

                    $curation = new Event();
                    $curation->setId((string) ($remoteEvent->id ?? ''));
                    $curation->setKind((int) ($remoteEvent->kind ?? $kind));
                    $curation->setPubkey((string) ($remoteEvent->pubkey ?? $pubkeyHex));
                    $curation->setContent((string) ($remoteEvent->content ?? ''));
                    $curation->setCreatedAt((int) ($remoteEvent->created_at ?? time()));
                    $curation->setTags(array_map(static function ($tag) {
                        if ($tag instanceof \stdClass) {
                            $tag = (array) $tag;
                        }

                        return is_array($tag) ? array_values($tag) : [];
                    }, is_array($remoteEvent->tags ?? null) ? $remoteEvent->tags : []));
                    $curation->setSig((string) ($remoteEvent->sig ?? ''));
                    $curation->extractAndSetDTag();
                }
            }
        }

        if (!$curation) {
            throw $this->createNotFoundException('Curation set not found');
        }

        $kind = $curation->getKind();

        // Determine type label
        $typeLabel = match($kind) {
            30004 => 'Articles/Notes',
            30005 => 'Videos',
            30006 => 'Pictures',
            default => 'Curation',
        };

        // Extract items from tags (both 'a' and 'e' tags)
        $items = [];
        $coordinates = [];
        $eventIds = [];
        $relayHints = [];

        foreach ($curation->getTags() as $tag) {
            if (!is_array($tag) || count($tag) < 2) continue;

            if ($tag[0] === 'a') {
                $coordinates[] = $tag[1];
                $items[] = [
                    'type' => 'coordinate',
                    'value' => $tag[1],
                    'relay' => $tag[2] ?? null,
                ];
            } elseif ($tag[0] === 'e') {
                $eventIds[] = $tag[1];
                if (isset($tag[2]) && is_string($tag[2]) && $tag[2] !== '') {
                    $relayHints[] = $tag[2];
                }
                $items[] = [
                    'type' => 'event',
                    'value' => $tag[1],
                    'relay' => $tag[2] ?? null,
                ];
            }
        }

        // For videos (30005) and pictures (30006), fetch media events
        $mediaItems = [];
        $mediaEvents = []; // Store actual Event objects for templates that need them
        $missingEventIds = [];
        if ($kind === KindsEnum::CURATION_VIDEOS->value || $kind === KindsEnum::CURATION_PICTURES->value) {
            // Fetch events by ID from database
            if (!empty($eventIds)) {
                $foundEvents = $repo->findBy(['id' => $eventIds]);
                $foundIds = [];
                foreach ($foundEvents as $mediaEvent) {
                    $mediaItems[] = $this->extractMediaFromEvent($mediaEvent);
                    $mediaEvents[] = $mediaEvent; // Keep the Event object
                    $foundIds[] = $mediaEvent->getId();
                }
                // Add placeholders for events not found
                foreach ($eventIds as $eventId) {
                    if (!in_array($eventId, $foundIds)) {
                        $missingEventIds[] = $eventId;
                        $mediaItems[] = [
                            'id' => $eventId,
                            'url' => null,
                            'thumb' => null,
                            'alt' => null,
                            'title' => null,
                            'mimeType' => null,
                            'pubkey' => null,
                            'createdAt' => null,
                            'kind' => null,
                            'notFound' => true,
                        ];
                        // Add placeholder object for mediaEvents
                        $mediaEvents[] = (object)[
                            'id' => $eventId,
                            'notFound' => true,
                        ];
                    }
                }
            }

            // Also handle coordinate-based references
            $missingCoordinates = [];
            foreach ($coordinates as $coord) {
                $parts = explode(':', $coord, 3);
                if (count($parts) === 3) {
                    [$coordKind, $author, $identifier] = $parts;
                    // Use indexed d_tag lookup (fast)
                    $coordEvent = $repo->findByNaddr((int)$coordKind, $author, $identifier);

                    if ($coordEvent) {
                        $mediaItems[] = $this->extractMediaFromEvent($coordEvent);
                        $mediaEvents[] = $coordEvent;
                    } else {
                        $missingCoordinates[] = $coord;
                        $mediaItems[] = [
                            'id' => null,
                            'url' => null,
                            'thumb' => null,
                            'alt' => null,
                            'title' => "Item: $identifier",
                            'mimeType' => null,
                            'pubkey' => $author,
                            'createdAt' => null,
                            'kind' => (int)$coordKind,
                            'coordinate' => $coord,
                            'notFound' => true,
                        ];
                        $mediaEvents[] = (object)[
                            'id' => null,
                            'coordinate' => $coord,
                            'notFound' => true,
                        ];
                    }
                }
            }
        }

        $missingEventIds = array_values(array_unique($missingEventIds));
        $missingCoordinates = array_values(array_unique($missingCoordinates ?? []));
        if ($missingEventIds !== [] || $missingCoordinates !== []) {
            $messageBus->dispatch(new FetchMissingCurationMediaMessage(
                $curation->getId(),
                $missingEventIds,
                array_values(array_unique($relayHints)),
                $pubkeyHex,
                $missingCoordinates,
            ));
        }

        // For articles/notes (30004), fetch articles similar to reading lists
        $articles = [];
        if ($kind === KindsEnum::CURATION_SET->value) {
            $articleRepo = $em->getRepository(Article::class);

            foreach ($coordinates as $coord) {
                $parts = explode(':', $coord, 3);
                if (count($parts) === 3) {
                    [$coordKind, $author, $articleSlug] = $parts;

                    $events = $articleRepo->findBy([
                        'slug' => $articleSlug,
                        'pubkey' => $author
                    ], ['createdAt' => 'DESC']);

                    $found = false;
                    foreach ($events as $event) {
                        if ($event->getSlug() === $articleSlug) {
                            $articles[] = $event;
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        $placeholder = (object)[
                            'pubkey' => $author,
                            'slug' => $articleSlug,
                            'coordinate' => $coord,
                            'kind' => (int)$coordKind,
                            'title' => null,
                        ];
                        $articles[] = $placeholder;
                    }
                }
            }
        }

        $logger->info('Curation set loaded', [
            'slug' => $slug,
            'kind' => $kind,
            'type' => $typeLabel,
            'items_count' => count($items),
            'media_count' => count($mediaItems),
            'articles_count' => count($articles),
        ]);

        // Choose template based on kind
        $template = match($kind) {
            30005 => 'pages/curation-videos.html.twig',
            30006 => 'pages/curation-pictures.html.twig',
            default => 'pages/curation-articles.html.twig',
        };

        $totalMissing = count($missingEventIds) + count($missingCoordinates ?? []);

        return $this->render($template, [
            'curation' => $curation,
            'type' => $typeLabel,
            'items' => $items,
            'mediaItems' => $mediaItems,
            'mediaEvents' => $mediaEvents,
            'articles' => $articles,
            'hasPendingMediaSync' => $totalMissing > 0,
            'curationMediaSyncTopic' => sprintf('/curation/%s/media-sync', $curation->getId()),
            'pendingMediaCount' => $totalMissing,
        ]);
    }

    /**
     * Extract media URL and metadata from an Event
     */
    private function extractMediaFromEvent(Event $event): array
    {
        $url = null;
        $alt = null;
        $title = null;
        $thumb = null;
        $mimeType = null;

        foreach ($event->getTags() as $tag) {
            if (!is_array($tag) || count($tag) < 2) continue;

            switch ($tag[0]) {
                case 'url':
                    $url = $tag[1];
                    break;
                case 'image':
                    if (!$url) $url = $tag[1];
                    break;
                case 'thumb':
                    $thumb = $tag[1];
                    break;
                case 'alt':
                    $alt = $tag[1];
                    break;
                case 'title':
                    $title = $tag[1];
                    break;
                case 'm':
                    $mimeType = $tag[1];
                    break;
            }
        }

        // Fallback: check content for URL
        if (!$url && filter_var($event->getContent(), FILTER_VALIDATE_URL)) {
            $url = $event->getContent();
        }

        return [
            'id' => $event->getId(),
            'url' => $url,
            'thumb' => $thumb ?? $url,
            'alt' => $alt,
            'title' => $title,
            'mimeType' => $mimeType,
            'pubkey' => $event->getPubkey(),
            'createdAt' => $event->getCreatedAt(),
            'kind' => $event->getKind(),
        ];
    }

    /**
     * AJAX endpoint to load more media events
     * @throws Exception
     */
    #[Route('/{vanity}/media/load-more', name: 'author-vanity-media-load-more')]
    #[Route('/p/{npub}/media/load-more', name: 'author-media-load-more', requirements: ['npub' => '^npub1.*'])]
    public function mediaLoadMore(Request $request, RedisCacheService $redisCacheService, string $npub = null, string $vanity = null): Response
    {
        $resolved = $this->resolveVanityOrRedirect($npub, $vanity, 'author-vanity-media-load-more');
        if ($resolved instanceof Response) {
            return $resolved;
        }

        $npub = $resolved['npub'];

        $page = $request->query->getInt('page', 2); // Default to page 2

        $keys = new Key();
        $pubkey = $keys->convertToHex($npub);

        // Get paginated data from cache - 24 items per page
        $paginatedData = $redisCacheService->getMediaEventsPaginated($pubkey, $page, 24);
        $mediaEvents = $paginatedData['events'];

        // Encode event IDs as note1... for each event
        foreach ($mediaEvents as $event) {
            $nip19 = new Nip19Helper();
            $event->noteId = $nip19->encodeNote($event->id);
        }

        return $this->json([
            'events' => array_map(function($event) {
                return [
                    'id' => $event->id,
                    'noteId' => $event->noteId,
                    'content' => $event->content ?? '',
                    'created_at' => $event->created_at,
                    'kind' => $event->kind,
                    'tags' => $event->tags ?? [],
                ];
            }, $mediaEvents),
            'hasMore' => $paginatedData['hasMore'],
            'page' => $paginatedData['page'],
            'total' => $paginatedData['total'],
        ]);
    }

    /**
     * Tab content endpoint - returns full page with layout or just tab content for Turbo Frames
     * @throws Exception
     * @throws InvalidArgumentException
     */
    #[Route('/{vanity}/{tab}', name: 'author-vanity-profile-tab', requirements: ['tab' => 'overview|articles|media|highlights|drafts|stats'])]
    #[Route('/p/{npub}/{tab}', name: 'author-profile-tab', requirements: ['npub' => '^npub1.*', 'tab' => 'overview|articles|media|highlights|drafts|stats'])]
    public function profileTab(
        string $tab,
        Request $request,
        RedisCacheService $redisCacheService,
        MessageBusInterface $messageBus,
        RedisViewStore $viewStore,
        RedisViewFactory $viewFactory,
        ArticleSearchInterface $articleSearch,
        EntityManagerInterface $em,
        VisitRepository $visitRepository,
        string $npub = null,
        string $vanity = null
    ): Response {
        $resolved = $this->resolveVanityOrRedirect($npub, $vanity, 'author-vanity-profile-tab', ['tab' => $tab]);
        if ($resolved instanceof Response) {
            return $resolved;
        }

        $npub = $resolved['npub'];
        $vanityName = $resolved['vanity'];
        $useVanity = $resolved['useVanity'];

        // Determine which identifier to use in URLs
        $profileId = $useVanity ? $vanityName : $npub;
        $routePrefix = $useVanity ? 'author-vanity-' : 'author-';

        $keys = new Key();
        $pubkey = $keys->convertToHex($npub);
        $authorMetadata = $redisCacheService->getMetadata($pubkey);
        $author = $authorMetadata->toStdClass(); // Convert to stdClass for template compatibility

        // Check ownership
        $currentUser = $this->getUser();
        $isOwner = $currentUser && $currentUser->getUserIdentifier() === $npub;

        // Private tabs require ownership
        $privateTabsRequireAuth = ['drafts', 'stats'];
        if (in_array($tab, $privateTabsRequireAuth) && !$isOwner) {
            // Check if this is a Turbo Frame request
            $isTurboFrameRequest = $request->headers->get('Turbo-Frame') === 'profile-tab-content';

            if ($isTurboFrameRequest) {
                return $this->render("profile/tabs/_{$tab}.html.twig", [
                    'isOwner' => false,
                    'npub' => $npub,
                    'pubkey' => $pubkey,
                    'profileId' => $profileId,
                    'useVanity' => $useVanity,
                    'routePrefix' => $routePrefix,
                ]);
            }

            // For direct access, show full page with tabs
            return $this->render('profile/author-tabs.html.twig', [
                'author' => $author,
                'npub' => $npub,
                'pubkey' => $pubkey,
                'isOwner' => false,
                'activeTab' => $tab,
                'mercure_public_hub_url' => $this->getParameter('mercure_public_hub_url'),
                'profileId' => $profileId,
                'useVanity' => $useVanity,
                'routePrefix' => $routePrefix,
            ]);
        }

        // STALE-WHILE-REVALIDATE: Try to get cached data first for instant loads
        $cacheableTabs = ['overview', 'articles', 'media', 'highlights', 'drafts'];
        $templateData = [];

        if (in_array($tab, $cacheableTabs)) {
            $cacheResult = $viewStore->fetchProfileTabData($pubkey, $tab);

            if ($cacheResult['isCached'] && $cacheResult['data'] !== null) {
                // Use cached data for instant response - convert arrays to objects for Twig
                $templateData = $this->hydrateTemplateData($cacheResult['data']);

                // If stale, trigger background revalidation
                if ($cacheResult['isStale']) {
                    try {
                        $messageBus->dispatch(new RevalidateProfileCacheMessage($pubkey, $tab, $isOwner));
                        $this->logger->debug('Profile cache stale, dispatched revalidation', [
                            'pubkey' => substr($pubkey, 0, 8),
                            'tab' => $tab,
                        ]);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to dispatch profile revalidation', ['error' => $e->getMessage()]);
                    }
                }
            } else {
                // Cache miss: load data synchronously, cache it, then dispatch revalidation for fresh data
                $templateData = match($tab) {
                    'overview' => $this->getOverviewTabData($pubkey, $isOwner, $redisCacheService, $viewStore, $viewFactory, $articleSearch, $messageBus, $em),
                    'articles' => $this->getArticlesTabData($pubkey, $isOwner, $viewStore, $viewFactory, $articleSearch),
                    'media' => $this->getMediaTabData($pubkey, $redisCacheService),
                    'highlights' => $this->getHighlightsTabData($pubkey, $em),
                    'drafts' => $this->getDraftsTabData($pubkey, $articleSearch, $viewFactory, $authorMetadata),
                    default => [],
                };

                // Cache the data for next request
                $viewStore->storeProfileTabData($pubkey, $tab, $templateData);

                // Dispatch background revalidation to get fresh data from relays
                try {
                    $messageBus->dispatch(new RevalidateProfileCacheMessage($pubkey, $tab, $isOwner));
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to dispatch profile revalidation on cache miss', ['error' => $e->getMessage()]);
                }
            }
        } else {
            // Non-cacheable tabs (stats) - load synchronously
            $templateData = match($tab) {
                'stats' => $this->getStatsTabData($npub, $visitRepository),
                default => [],
            };
        }

        // Check if this is a Turbo Frame request (AJAX partial load)
        $isTurboFrameRequest = $request->headers->get('Turbo-Frame') === 'profile-tab-content';

        if ($isTurboFrameRequest) {
            // Return just the tab partial for Turbo Frame
            return $this->render("profile/tabs/_{$tab}.html.twig", array_merge([
                'author' => $author,
                'npub' => $npub,
                'pubkey' => $pubkey,
                'isOwner' => $isOwner,
                'mercure_public_hub_url' => $this->getParameter('mercure_public_hub_url'),
                'profileId' => $profileId,
                'useVanity' => $useVanity,
                'routePrefix' => $routePrefix,
            ], $templateData));
        }

        // Direct access - return full page with layout and tabs
        return $this->render('profile/author-tabs.html.twig', array_merge([
            'author' => $author,
            'npub' => $npub,
            'pubkey' => $pubkey,
            'isOwner' => $isOwner,
            'activeTab' => $tab,
            'mercure_public_hub_url' => $this->getParameter('mercure_public_hub_url'),
            'profileId' => $profileId,
            'useVanity' => $useVanity,
            'routePrefix' => $routePrefix,
        ], $templateData));
    }

    /**
     * Get articles for the articles tab
     */
    private function getArticlesTabData(
        string $pubkey,
        bool $isOwner,
        RedisViewStore $viewStore,
        RedisViewFactory $viewFactory,
        ArticleSearchInterface $articleSearch
    ): array {
        $articles = $this->getAuthorArticles($pubkey, $isOwner, $viewStore, $viewFactory, $articleSearch);
        return ['articles' => $articles];
    }

    /**
     * Get overview tab data - mix of recent content
     */
    private function getOverviewTabData(
        string $pubkey,
        bool $isOwner,
        RedisCacheService $redisCacheService,
        RedisViewStore $viewStore,
        RedisViewFactory $viewFactory,
        ArticleSearchInterface $articleSearch,
        MessageBusInterface $messageBus,
        EntityManagerInterface $em
    ): array {
        // Note: Background revalidation via RevalidateProfileCacheMessage
        // will dispatch FetchAuthorContentMessage asynchronously

        // Get author's magazines
        $authorMagazines = $this->getAuthorMagazines($pubkey, $em);


        // Get recent articles (limit to 3 for overview)
        $allArticles = $this->getAuthorArticles($pubkey, false, $viewStore, $viewFactory, $articleSearch);
        $recentArticles = array_slice($allArticles, 0, 3);

        // Get recent media (limit to 6 for overview)
        $mediaData = $this->getMediaTabData($pubkey, $redisCacheService);
        $recentMedia = array_slice($mediaData['mediaEvents'] ?? [], 0, 6);

        // Get recent highlights (limit to 6 for overview)
        $highlightsData = $this->getHighlightsTabData($pubkey, $em);
        $recentHighlights = array_slice($highlightsData['highlights'] ?? [], 0, 3);

        return [
            'authorMagazines' => $authorMagazines,
            'recentArticles' => $recentArticles,
            'recentMedia' => $recentMedia,
            'recentHighlights' => $recentHighlights,
        ];
    }

    /**
     * Get media events for the media tab
     */
    private function getMediaTabData(string $pubkey, RedisCacheService $redisCacheService): array
    {
        $paginatedData = $redisCacheService->getMediaEventsPaginated($pubkey, 1, 24);
        $mediaEvents = $paginatedData['events'];

        foreach ($mediaEvents as $event) {
            $nip19 = new Nip19Helper();
            $event->noteId = $nip19->encodeNote($event->id);
        }

        return [
            'mediaEvents' => $mediaEvents,
            'hasMore' => $paginatedData['hasMore'],
            'total' => $paginatedData['total'],
        ];
    }

    /**
     * Get magazines published by the given pubkey.
     * Tries the Magazine entity first; falls back to Event table if empty.
     *
     * @return array
     */
    private function getAuthorMagazines(string $pubkey, EntityManagerInterface $em): array
    {
        // Prefer projected Magazine entities
        $magazines = $em->getRepository(Magazine::class)->findByPubkey($pubkey);
        if (!empty($magazines)) {
            return $magazines;
        }

        // Fallback: query Event table (same logic as ZineList used to use)
        $allIndices = $em->getRepository(Event::class)->findBy([
            'kind' => KindsEnum::PUBLICATION_INDEX,
            'pubkey' => $pubkey,
        ]);

        $filtered = array_filter($allIndices, function (Event $event) {
            $tags = $event->getTags();
            $isMagType = false;
            $isTopLevel = false;
            foreach ($tags as $tag) {
                if (($tag[0] ?? '') === 'type' && ($tag[1] ?? '') === 'magazine') {
                    $isMagType = true;
                }
                if (($tag[0] ?? '') === 'a' && !$isTopLevel) {
                    $parts = explode(':', $tag[1] ?? '');
                    if (($parts[0] ?? '') === (string) KindsEnum::PUBLICATION_INDEX->value) {
                        $isTopLevel = true;
                    }
                }
            }
            return $isMagType && $isTopLevel;
        });

        // Deduplicate by slug, keeping the newest
        $bySlug = [];
        foreach ($filtered as $mag) {
            $slug = $mag->getSlug();
            if ($slug === null) {
                continue;
            }
            if (!isset($bySlug[$slug]) || $mag->getCreatedAt() > $bySlug[$slug]->getCreatedAt()) {
                $bySlug[$slug] = $mag;
            }
        }

        // Convert Event entities to plain arrays for cache serialization
        return array_values(array_map(function (Event $event) {
            $title = null;
            $summary = null;
            foreach ($event->getTags() as $tag) {
                if (($tag[0] ?? '') === 'title' && isset($tag[1])) {
                    $title = $tag[1];
                }
                if (($tag[0] ?? '') === 'summary' && isset($tag[1])) {
                    $summary = $tag[1];
                }
            }
            return [
                'slug' => $event->getSlug(),
                'title' => $title,
                'summary' => $summary,
            ];
        }, $bySlug));
    }

    /**
     * Get highlights for the highlights tab
     */
    private function getHighlightsTabData(string $pubkey, EntityManagerInterface $em): array
    {
        $repo = $em->getRepository(Event::class);
        $events = $repo->findBy(
            ['pubkey' => $pubkey, 'kind' => KindsEnum::HIGHLIGHTS->value],
            ['created_at' => 'DESC'],
            50
        );

        $highlights = [];
        foreach ($events as $event) {
            $context = null;
            $sourceUrl = null;
            $articleRef = null;
            $articleTitle = null;
            $articleAuthor = null;
            $url = null;
            $relayHints = [];

            // Extract metadata from tags
            foreach ($event->getTags() as $tag) {
                if (!is_array($tag) || count($tag) < 2) {
                    continue;
                }

                switch ($tag[0]) {
                    case 'context':
                        $context = $tag[1] ?? null;
                        break;
                    case 'r': // URL reference
                        if (!$sourceUrl) {
                            $sourceUrl = $tag[1] ?? null;
                        }
                        if (!$url) {
                            $url = $tag[1] ?? null;
                        }
                        // Collect relay hints
                        if (isset($tag[1]) && str_starts_with($tag[1], 'wss://')) {
                            $relayHints[] = $tag[1];
                        }
                        break;
                    case 'a': // Article reference (kind:pubkey:identifier)
                    case 'A':
                        $articleRef = $tag[1] ?? null;
                        // Get relay hint if available
                        if (isset($tag[2]) && str_starts_with($tag[2], 'wss://')) {
                            $relayHints[] = $tag[2];
                        }
                        // Parse to check if it's an article (kind 30023)
                        $parts = explode(':', $tag[1] ?? '', 3);
                        if (count($parts) === 3 && $parts[0] === '30023') {
                            $articleAuthor = $parts[1];
                        }
                        break;
                    case 'title':
                        $articleTitle = $tag[1] ?? null;
                        break;
                }
            }

            $highlight = (object)[
                'id' => $event->getId(),
                'content' => $event->getContent(),
                'context' => $context,
                'sourceUrl' => $sourceUrl,
                'createdAt' => $event->getCreatedAt(),
                'article_ref' => $articleRef,
                'article_title' => $articleTitle,
                'article_author' => $articleAuthor,
                'url' => $url,
                'naddr' => null,
                'preview' => null,
            ];

            // Generate naddr if we have an article reference
            if ($articleRef && str_starts_with($articleRef, '30023:')) {
                $highlight->naddr = $this->generateNaddr($articleRef, $relayHints);

                // Create preview data if we have naddr
                if ($highlight->naddr) {
                    $highlight->preview = $this->createPreviewData($highlight->naddr);
                }
            }

            $highlights[] = $highlight;
        }

        return ['highlights' => $highlights];
    }

    /**
     * Generate naddr from coordinate (kind:pubkey:identifier) and relay hints
     */
    private function generateNaddr(string $coordinate, array $relayHints = []): ?string
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            $kind = (int)$parts[0];
            $pubkey = $parts[1];
            $identifier = $parts[2];

            $naddr = \nostriphant\NIP19\Bech32::naddr(
                kind: $kind,
                pubkey: $pubkey,
                identifier: $identifier,
                relays: $relayHints
            );

            return (string)$naddr;

        } catch (\Throwable $e) {
            $this->logger->warning('Failed to generate naddr', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create preview data structure for NostrPreview component
     */
    private function createPreviewData(string $naddr): ?array
    {
        try {
            // Use NostrLinkParser to parse the naddr identifier
            $links = $this->nostrLinkParser->parseLinks("nostr:$naddr");

            if (!empty($links)) {
                return $links[0];
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->debug('Failed to create preview data', [
                'naddr' => $naddr,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get drafts for the drafts tab (owner only)
     */
    private function getDraftsTabData(
        string $pubkey,
        ArticleSearchInterface $articleSearch,
        RedisViewFactory $viewFactory,
        object $author
    ): array {
        $allArticles = $articleSearch->findByPubkey($pubkey, 100, 0);
        $drafts = [];

        foreach ($allArticles as $article) {
            if ($article instanceof Article && $article->getKind() === KindsEnum::LONGFORM_DRAFT) {
                $baseObject = $viewFactory->articleBaseObject($article, $author);
                $normalized = $viewFactory->normalizeBaseObject($baseObject);
                if (isset($normalized['article'])) {
                    $drafts[] = (object) $normalized['article'];
                }
            }
        }

        // Deduplicate by slug
        $slugMap = [];
        foreach ($drafts as $draft) {
            $slug = $draft->slug ?? null;
            if ($slug && (!isset($slugMap[$slug]) || ($draft->createdAt ?? 0) > ($slugMap[$slug]->createdAt ?? 0))) {
                $slugMap[$slug] = $draft;
            }
        }

        return ['drafts' => array_values($slugMap)];
    }


    /**
     * Get visit statistics for the stats tab (owner only)
     */
    private function getStatsTabData(string $npub, VisitRepository $visitRepository): array
    {
        // Total visits for different time periods
        $visitsLast24Hours = $visitRepository->countVisitsForNpubSince($npub, new \DateTimeImmutable('-24 hours'));
        $visitsLast7Days = $visitRepository->countVisitsForNpubSince($npub, new \DateTimeImmutable('-7 days'));
        $visitsLast30Days = $visitRepository->countVisitsForNpubSince($npub, new \DateTimeImmutable('-30 days'));

        // Unique visitors for different time periods
        $uniqueVisitorsLast24Hours = $visitRepository->countUniqueVisitorsForNpubSince($npub, new \DateTimeImmutable('-24 hours'));
        $uniqueVisitorsLast7Days = $visitRepository->countUniqueVisitorsForNpubSince($npub, new \DateTimeImmutable('-7 days'));
        $uniqueVisitorsLast30Days = $visitRepository->countUniqueVisitorsForNpubSince($npub, new \DateTimeImmutable('-30 days'));

        // Top articles
        $topArticlesLast7Days = $visitRepository->getMostVisitedArticlesForNpub($npub, new \DateTimeImmutable('-7 days'), 10);
        $topArticlesLast30Days = $visitRepository->getMostVisitedArticlesForNpub($npub, new \DateTimeImmutable('-30 days'), 10);

        // Visits per day (last 30 days) - sparse array, only days with visits
        $dailyVisitCountsRaw = $visitRepository->getVisitsPerDayForNpub($npub, 30);

        // Daily unique visitors (last 30 days) - full array with all days
        $dailyUniqueVisitors = $visitRepository->getDailyUniqueVisitorsForNpub($npub, 30);

        // Create a lookup map for visits by day
        $visitsMap = [];
        foreach ($dailyVisitCountsRaw as $row) {
            $visitsMap[$row['day']] = (int)$row['count'];
        }

        // Merge into aligned chart data using unique visitors days as the base (has all 30 days)
        $chartData = [];
        foreach ($dailyUniqueVisitors as $dayData) {
            $day = $dayData['day'];
            $chartData[] = [
                'day' => $day,
                'visits' => $visitsMap[$day] ?? 0,
                'uniqueVisitors' => (int)$dayData['count'],
            ];
        }

        // Visit breakdown (profile vs articles)
        $visitBreakdownLast7Days = $visitRepository->getVisitBreakdownForNpub($npub, new \DateTimeImmutable('-7 days'));
        $visitBreakdownLast30Days = $visitRepository->getVisitBreakdownForNpub($npub, new \DateTimeImmutable('-30 days'));

        return [
            'visitsLast24Hours' => $visitsLast24Hours,
            'visitsLast7Days' => $visitsLast7Days,
            'visitsLast30Days' => $visitsLast30Days,
            'uniqueVisitorsLast24Hours' => $uniqueVisitorsLast24Hours,
            'uniqueVisitorsLast7Days' => $uniqueVisitorsLast7Days,
            'uniqueVisitorsLast30Days' => $uniqueVisitorsLast30Days,
            'topArticlesLast7Days' => $topArticlesLast7Days,
            'topArticlesLast30Days' => $topArticlesLast30Days,
            'chartData' => $chartData,
            'visitBreakdownLast7Days' => $visitBreakdownLast7Days,
            'visitBreakdownLast30Days' => $visitBreakdownLast30Days,
        ];
    }

    /**
     * Helper to get author articles (used by both unified profile and tab)
     */
    private function getAuthorArticles(
        string $pubkey,
        bool $isOwner,
        RedisViewStore $viewStore,
        RedisViewFactory $viewFactory,
        ArticleSearchInterface $articleSearch
    ): array {
        $cachedArticles = $viewStore->fetchUserArticles($pubkey);
        $viewData = [];

        if ($cachedArticles !== null) {
            foreach ($cachedArticles as $baseObject) {
                if (isset($baseObject['article'])) {
                    $articleData = $baseObject['article'];
                    $kind = $articleData['kind'] ?? null;
                    $slug = $articleData['slug'] ?? null;

                    // Skip drafts (they go in drafts tab)
                    if ($kind === KindsEnum::LONGFORM_DRAFT->value) {
                        continue;
                    }

                    if ($slug) {
                        $viewData[] = (object) $articleData;
                    }
                }
            }
            $viewData = $this->deduplicateViewData($viewData);
        } else {
            $articles = $articleSearch->findByPubkey($pubkey, 100, 0);
            $articles = $this->filterAndDeduplicateArticles($articles, false); // Always filter out drafts for articles tab

            foreach ($articles as $article) {
                if ($article instanceof Article) {
                    try {
                        $baseObject = $viewFactory->articleBaseObject($article, null);
                        $normalized = $viewFactory->normalizeBaseObject($baseObject);
                        if (isset($normalized['article'])) {
                            $viewData[] = (object) $normalized['article'];
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to build article view', ['error' => $e->getMessage()]);
                    }
                }
            }
        }

        return $viewData;
    }

    /**
     * Filter and deduplicate articles:
     * - Hide drafts (kind 30024) unless viewing own profile
     * - Show only the latest version per slug
     * - Only handles Article entities (not cached arrays)
     */
    private function filterAndDeduplicateArticles(array $articles, bool $isOwnProfile): array
    {
        $slugMap = [];

        foreach ($articles as $article) {
            // Only handle Article entities - no more mixed format handling
            if (!$article instanceof Article) {
                continue;
            }

            $kind = $article->getKind();
            $slug = $article->getSlug();
            $createdAt = $article->getCreatedAt();

            // Skip drafts unless viewing own profile
            if (!$isOwnProfile && $kind === KindsEnum::LONGFORM_DRAFT) {
                continue;
            }

            // Skip if no slug
            if (!$slug) {
                continue;
            }

            // Keep only the latest version per slug
            if (!isset($slugMap[$slug]) || $createdAt > $slugMap[$slug]['createdAt']) {
                $slugMap[$slug] = [
                    'article' => $article,
                    'createdAt' => $createdAt
                ];
            }
        }

        // Extract just the articles, sorted by creation date (newest first)
        $filtered = array_column($slugMap, 'article');
        usort($filtered, function($a, $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt(); // Descending order
        });

        return $filtered;
    }

    /**
     * Deduplicate cached view data by slug (keep latest version)
     * Handles objects with slug and createdAt properties
     */
    private function deduplicateViewData(array $viewData): array
    {
        $slugMap = [];

        foreach ($viewData as $item) {
            $slug = $item->slug ?? null;
            $createdAt = $item->createdAt ?? null;

            if (!$slug) {
                continue;
            }

            // Parse createdAt to comparable format
            if (is_string($createdAt)) {
                $timestamp = strtotime($createdAt);
            } else if ($createdAt instanceof \DateTimeInterface) {
                $timestamp = $createdAt->getTimestamp();
            } else {
                $timestamp = 0;
            }

            // Keep only the latest version per slug
            if (!isset($slugMap[$slug]) || $timestamp > $slugMap[$slug]['timestamp']) {
                $slugMap[$slug] = [
                    'item' => $item,
                    'timestamp' => $timestamp
                ];
            }
        }

        // Extract items and sort by timestamp (newest first)
        $deduplicated = array_column($slugMap, 'item');
        usort($deduplicated, function($a, $b) {
            $timeA = is_string($a->createdAt ?? '') ? strtotime($a->createdAt) : 0;
            $timeB = is_string($b->createdAt ?? '') ? strtotime($b->createdAt) : 0;
            return $timeB <=> $timeA; // Descending order
        });

        return $deduplicated;
    }

    /**
     * Convert cached array data back to objects for Twig template compatibility.
     * Twig can access both array keys and object properties, but some components expect objects.
     */
    private function hydrateTemplateData(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Check if this is a list of items that should be objects
                if ($this->isIndexedArray($value)) {
                    $result[$key] = array_map(fn($item) => is_array($item) ? (object) $item : $item, $value);
                } else {
                    // Keep associative arrays as-is (Twig handles these fine)
                    $result[$key] = $value;
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Check if an array is indexed (list) vs associative
     */
    private function isIndexedArray(array $arr): bool
    {
        if (empty($arr)) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }


    /**
     * Author profile - redirect to overview tab by default
     */
    #[Route('/{vanity}', name: 'author-vanity-profile', priority: -10)]
    #[Route('/p/{npub}', name: 'author-profile', requirements: ['npub' => '^npub1.*'])]
    public function index(string $npub = null, string $vanity = null): Response
    {
        $resolved = $this->resolveVanityOrRedirect($npub, $vanity, 'author-vanity-profile-tab', ['tab' => 'overview']);
        if ($resolved instanceof Response) {
            return $resolved;
        }

        $npub = $resolved['npub'];
        $useVanity = $resolved['useVanity'];
        $vanityName = $resolved['vanity'];

        // Redirect to overview tab with the same identifier type
        if ($useVanity) {
            return $this->redirectToRoute('author-vanity-profile-tab', ['vanity' => $vanityName, 'tab' => 'overview']);
        }

        // Redirect to overview tab - shows dashboard with mix of content
        return $this->redirectToRoute('author-profile-tab', ['npub' => $npub, 'tab' => 'overview']);
    }

    /**
     * AJAX endpoint to render articles from JSON input
     * @param Request $request
     * @param SerializerInterface $serializer
     * @return Response
     */
    #[Route('/articles/render', name: 'render_articles', options: ['csrf_protection' => false], methods: ['POST'])]
    public function renderArticles(Request $request, SerializerInterface $serializer): Response
    {

        $data = json_decode($request->getContent(), true);
        $articlesJson = json_encode($data['articles'] ?? []);
        $articles = $serializer->deserialize($articlesJson, Article::class.'[]', 'json');

        // Render the articles using the template
        return $this->render('articles.html.twig', [
            'articles' => $articles
        ]);
    }

    /**
     * Redirect from /p/{pubkey} (hex format) to /p/{npub} (bech32 format)
     * This route must be AFTER the npub route to avoid conflicts
     * @throws Exception
     */
    #[Route('/p/{pubkey}', name: 'author-redirect', requirements: ['pubkey' => '^(?!npub1)[0-9a-f]{64}$'])]
    public function authorRedirect($pubkey): Response
    {
        $keys = new Key();
        $npub = $keys->convertPublicKeyToBech32($pubkey);
        return $this->redirectToRoute('author-profile', ['npub' => $npub]);
    }
}
