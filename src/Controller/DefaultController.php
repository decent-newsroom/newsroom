<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Enum\RolesEnum;
use App\Helper\NavigationBuilderTrait;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\Graph\GraphMagazineListService;
use App\Service\Nostr\NostrEventParser;
use App\Service\ReadingListNavigationService;
use App\Service\Search\ArticleSearchFactory;
use App\Service\Search\ContentSearchService;
use App\Util\CommonMark\Converter;
use App\Util\ForumTopics;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use swentel\nostr\Nip19\Nip19Helper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\LatestArticles\LatestArticlesExclusionPolicy;
use App\Service\Nostr\NostrClient;
use App\Service\UserMuteListService;
use App\Service\HighlightFeedService;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;

class DefaultController extends AbstractController
{
    use NavigationBuilderTrait;

    /**
     * Hydrate an Event from a raw DB row without going through a full ORM query.
     */
    private function hydrateEventFromRow(array $row): Event
    {
        $event = new Event();
        $event->setId((string) ($row['id'] ?? ''));
        if (isset($row['event_id'])) {
            $event->setEventId((string) $row['event_id']);
        }
        $event->setKind((int) ($row['kind'] ?? 0));
        $event->setPubkey((string) ($row['pubkey'] ?? ''));
        $event->setContent((string) ($row['content'] ?? ''));
        $event->setCreatedAt((int) ($row['created_at'] ?? 0));
        $event->setSig($row['sig'] ?? null);

        $tags = $row['tags'] ?? [];
        if (is_string($tags)) {
            $tags = json_decode($tags, true) ?? [];
        }
        $event->setTags(is_array($tags) ? $tags : []);

        return $event;
    }

    /**
     * Find latest kind:30040 index for a magazine slug using indexed d_tag first.
     */
    private function findLatestMagazineIndexBySlug(string $slug, EventRepository $eventRepository): ?Event
    {
        $conn = $eventRepository->getEntityManager()->getConnection();

        $row = $conn->executeQuery(
            'SELECT * FROM event e WHERE e.kind = :kind AND e.d_tag = :slug ORDER BY e.created_at DESC LIMIT 1',
            [
                'kind' => KindsEnum::PUBLICATION_INDEX->value,
                'slug' => $slug,
            ],
        )->fetchAssociative();

        if ($row === false) {
            // Backward compatibility for rows that predate d_tag backfill.
            $row = $conn->executeQuery(
                "SELECT * FROM event e
                 WHERE e.kind = :kind
                   AND EXISTS (
                       SELECT 1 FROM jsonb_array_elements(e.tags) AS tag
                       WHERE tag->>0 = 'd' AND tag->>1 = :slug
                   )
                 ORDER BY e.created_at DESC
                 LIMIT 1",
                [
                    'kind' => KindsEnum::PUBLICATION_INDEX->value,
                    'slug' => $slug,
                ],
            )->fetchAssociative();
        }

        return $row !== false ? $this->hydrateEventFromRow($row) : null;
    }

    /**
     * Parse magazine tags into categories, chapters and optional front-page article coordinate.
     *
     * @return array{categoryTags: array<int, array<mixed>>, chapterCoordinates: string[], frontPageArticleCoordinate: ?string}
     */
    private function parseMagazineStructure(Event $magazine): array
    {
        $categoryTags = [];
        $chapterCoordinates = [];
        $frontPageArticleCoordinate = null;

        foreach ($magazine->getTags() as $tag) {
            if (!isset($tag[0], $tag[1]) || $tag[0] !== 'a') {
                continue;
            }

            $parts = explode(':', (string) $tag[1], 3);
            if (count($parts) !== 3) {
                continue;
            }

            $kind = (int) $parts[0];
            if ($kind === KindsEnum::PUBLICATION_INDEX->value) {
                $categoryTags[] = $tag;
                continue;
            }

            if ($kind === KindsEnum::PUBLICATION_CONTENT->value) {
                $chapterCoordinates[] = (string) $tag[1];
                continue;
            }

            if (($kind === KindsEnum::LONGFORM->value || $kind === KindsEnum::LONGFORM_DRAFT->value) && $frontPageArticleCoordinate === null) {
                $frontPageArticleCoordinate = (string) $tag[1];
            }
        }

        return [
            'categoryTags' => $categoryTags,
            'chapterCoordinates' => $chapterCoordinates,
            'frontPageArticleCoordinate' => $frontPageArticleCoordinate,
        ];
    }

    /**
     * @param array<int, array<mixed>> $categoryTags
     * @return array<int, array{categorySlug: string, categoryTitle: string, articleCoordinate: ?string}>
     */
    private function buildCategoryPreviewPayload(array $categoryTags, EventRepository $eventRepository): array
    {
        if ($categoryTags === []) {
            return [];
        }

        $categoryCoordinates = [];
        foreach ($categoryTags as $tag) {
            if (!isset($tag[1]) || !is_string($tag[1])) {
                continue;
            }
            $categoryCoordinates[] = $tag[1];
        }

        if ($categoryCoordinates === []) {
            return [];
        }

        $categoryMap = $eventRepository->findByCoordinates($categoryCoordinates);
        $payload = [];

        foreach ($categoryCoordinates as $coordinate) {
            $parts = explode(':', $coordinate, 3);
            $categorySlug = $parts[2] ?? '';
            if ($categorySlug === '') {
                continue;
            }

            $categoryEvent = $categoryMap[$coordinate] ?? null;
            if (!$categoryEvent instanceof Event) {
                $payload[] = [
                    'categorySlug' => $categorySlug,
                    'categoryTitle' => $categorySlug,
                    'articleCoordinate' => null,
                ];
                continue;
            }

            $payload[] = [
                'categorySlug' => $categorySlug,
                'categoryTitle' => $categoryEvent->getTitle() ?? $categorySlug,
                'articleCoordinate' => $this->findFirstCategoryArticleCoordinate($categoryEvent),
            ];
        }

        return $payload;
    }

    private function findFirstCategoryArticleCoordinate(Event $categoryEvent): ?string
    {
        foreach ($categoryEvent->getTags() as $tag) {
            if (!isset($tag[0], $tag[1]) || $tag[0] !== 'a' || !is_string($tag[1])) {
                continue;
            }

            $parts = explode(':', $tag[1], 3);
            if (count($parts) !== 3) {
                continue;
            }

            $kind = (int) $parts[0];
            if ($kind === KindsEnum::LONGFORM->value || $kind === KindsEnum::LONGFORM_DRAFT->value) {
                return $tag[1];
            }
        }

        return null;
    }

    /**
     * Build chapter cards for magazine templates using one batch DB lookup.
     *
     * @param string[] $chapterCoordinates
     * @return array<int, array{event: ?Event, coordinate: string, fetched: bool, slug?: string, pubkey?: string, kind?: int}>
     */
    private function resolveMagazineChapters(array $chapterCoordinates, EventRepository $eventRepository): array
    {
        if ($chapterCoordinates === []) {
            return [];
        }

        $chapterMap = $eventRepository->findByCoordinates($chapterCoordinates);
        $chapters = [];
        foreach ($chapterCoordinates as $coordinate) {
            $parts = explode(':', $coordinate, 3);
            if (count($parts) !== 3) {
                continue;
            }

            $kind = (int) $parts[0];
            $pubkey = $parts[1];
            $slug = $parts[2];

            $chapter = $chapterMap[$coordinate] ?? null;
            if ($chapter instanceof Event) {
                $chapters[] = [
                    'event' => $chapter,
                    'coordinate' => $coordinate,
                    'fetched' => true,
                ];
                continue;
            }

            $chapters[] = [
                'event' => null,
                'coordinate' => $coordinate,
                'slug' => $slug,
                'pubkey' => $pubkey,
                'kind' => $kind,
                'fetched' => false,
            ];
        }

        return $chapters;
    }

    /**
     * @throws Exception
     */
    #[Route('/', name: 'home', condition: "!request.attributes.has('_chat_community')")]
    public function index(): Response
    {
        if ($this->getUser()) {
            return $this->render('home_authenticated.html.twig');
        }

        return $this->render('home.html.twig');
    }

    /**
     * @throws Exception
     */
    #[Route('/newsstand', name: 'newsstand')]
    public function newsstand(): Response
    {
        return $this->render('pages/newsstand.html.twig');
    }

    /**
     * @deprecated The bookshelf page has been deprecated. The route is kept for backward
     *             compatibility and now permanently redirects to the newsstand.
     */
    #[Route('/bookshelf', name: 'bookshelf')]
    public function bookshelf(): Response
    {
        return $this->redirectToRoute('newsstand', [], 301);
    }

    /**
     * My Magazines – newsstand filtered for the current user
     */
    #[Route('/my-magazines', name: 'my_magazines')]
    public function myMagazines(): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('newsstand');
        }

        $npub = $user->getUserIdentifier();
        try {
            $key = new Key();
            $pubkey = $key->convertToHex($npub);
        } catch (\Throwable $e) {
            return $this->redirectToRoute('newsstand');
        }

        return $this->render('pages/my-magazines.html.twig', [
            'newsroomNav' => $this->buildNewsroomNav(),
            'pubkey' => $pubkey,
        ]);
    }

    /**
     * Global magazines manifest - lists all available magazines
     */
    #[Route('/magazines/manifest.json', name: 'magazines-manifest')]
    public function magazinesManifest(
        EntityManagerInterface $entityManager,
        HiddenCoordinateRepository $hiddenCoordinateRepo,
        LoggerInterface $logger
    ): JsonResponse
    {
        try {
            // Get all magazine indices from database
            $nzines = $entityManager->getRepository(Event::class)->findBy(
                ['kind' => KindsEnum::PUBLICATION_INDEX],
                ['created_at' => 'DESC']
            );

            // Load hidden coordinates (graceful if migration not yet run)
            try {
                $hiddenCoordinates = $hiddenCoordinateRepo->findAllCoordinates();
            } catch (\Throwable) {
                $hiddenCoordinates = [];
            }

            // Group by slug and keep only the latest version of each
            $magazinesBySlug = [];
            foreach ($nzines as $magazine) {
                $slug = $magazine->getSlug();

                // Skip hidden coordinates
                $coord = sprintf('30040:%s:%s', $magazine->getPubkey(), $slug);
                if (!empty($hiddenCoordinates) && in_array($coord, $hiddenCoordinates, true)) {
                    continue;
                }

                if (!isset($magazinesBySlug[$slug]) ||
                    $magazine->getCreatedAt() > $magazinesBySlug[$slug]->getCreatedAt()) {
                    $magazinesBySlug[$slug] = $magazine;
                }
            }

            $magazines = [];
            foreach ($magazinesBySlug as $slug => $magazine) {
                // Count categories
                $categoryCount = 0;
                if ($magazine->getTags()) {
                    foreach ($magazine->getTags() as $tag) {
                        if (isset($tag[0]) && $tag[0] === 'a') {
                            $categoryCount++;
                        }
                    }
                }

                $magazines[] = [
                    'slug' => $slug,
                    'title' => $magazine->getTitle(),
                    'summary' => $magazine->getSummary(),
                    'image' => $magazine->getImage(),
                    'language' => $magazine->getLanguage(),
                    'pubkey' => $magazine->getPubkey(),
                    'createdAt' => (new \DateTime())->setTimestamp($magazine->getCreatedAt())->format('c'),
                    'categoryCount' => $categoryCount,
                    'url' => $this->generateUrl('magazine-index', ['mag' => $slug], 0),
                    'manifestUrl' => $this->generateUrl('magazine-manifest', ['mag' => $slug], 0),
                ];
            }

            // Sort by title
            usort($magazines, function ($a, $b) {
                return strcasecmp($a['title'], $b['title']);
            });

            $manifest = [
                '@context' => 'https://schema.org',
                '@type' => 'DataCatalog',
                'name' => 'Newsroom Magazines',
                'description' => 'Collection of all magazines available on Newsroom',
                'version' => '1.0',
                'generatedAt' => (new \DateTime())->format('c'),
                'url' => $this->generateUrl('newsstand', [], 0),
                'dataset' => $magazines,
                'stats' => [
                    'totalMagazines' => count($magazines),
                    'totalCategories' => array_sum(array_column($magazines, 'categoryCount')),
                ],
            ];

            return new JsonResponse($manifest, 200, [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'public, max-age=600', // Cache for 10 minutes
            ]);

        } catch (\Exception $e) {
            $logger->error('Failed to generate magazines manifest', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse([
                'error' => 'Failed to generate manifest',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @throws Exception|InvalidArgumentException
     */
    #[Route('/discover', name: 'discover')]
    public function discover(
        RedisCacheService $redisCacheService,
        UserEntityRepository $userRepository,
        ArticleRepository $articleRepository,
        HighlightFeedService $highlightFeedService,
        NostrClient $nostrClient,
        EntityManagerInterface $entityManager,
        GraphMagazineListService $graphMagazineList,
    ): Response
    {
        $user = $this->getUser();

        // Highlights tab: use the same Redis-first + repository fallback source as /highlights.
        $highlights = [];
        try {
            $highlightsFeed = $highlightFeedService->loadLatestHighlights(200);
            $highlights = $highlightsFeed['highlights'];
        } catch (\Throwable) {
            // Non-critical; proceed with empty highlights.
        }

        // ── Editorial Tab (Magazines + Follow Packs + Collections) ────────
        $editorial = [];
        try {
            // Fetch all users with ROLE_EDITOR and collect their hex pubkeys
            $editorUsers = $userRepository->findByRoleWithQuery(RolesEnum::EDITOR->value, null, 10000);
            $editorPubkeys = [];
            foreach ($editorUsers as $editorUser) {
                try {
                    $npub = $editorUser->getNpub();
                    if (NostrKeyUtil::isNpub($npub)) {
                        $editorPubkeys[] = NostrKeyUtil::npubToHex($npub);
                    }
                } catch (\Throwable) {
                    // Skip editor with invalid npub
                }
            }

            $repo = $entityManager->getRepository(Event::class);

            // Fetch magazines via the graph service (same source as the newsstand)
            $magazineRows = $graphMagazineList->listAllMagazines();
            foreach ($magazineRows as $row) {
                // Only include magazines from editor users
                if (!in_array($row['pubkey'] ?? '', $editorPubkeys, true)) {
                    continue;
                }
                $slug = $row['slug'] ?? $row['d_tag'] ?? null;
                if (!$slug) {
                    continue;
                }
                $editorial[] = [
                    'kind'       => KindsEnum::PUBLICATION_INDEX->value,
                    'title'      => $row['title'] ?? 'Untitled',
                    'summary'    => $row['summary'] ?? null,
                    'slug'       => $slug,
                    'pubkey'     => $row['pubkey'],
                    'image'      => $row['image'] ?? null,
                    'created_at' => 0, // graph service doesn't expose this; sort handled below
                ];
            }

            // Fetch follow packs (kind 39089) from the Event table
            $followPacks = $repo->findBy(
                ['kind' => KindsEnum::FOLLOW_PACK->value],
                ['created_at' => 'DESC'],
                50
            );
            foreach ($followPacks as $event) {
                // Only include follow packs from editor users
                if (!in_array($event->getPubkey(), $editorPubkeys, true)) {
                    continue;
                }
                $slug = $event->getSlug();
                if (!$slug) {
                    continue;
                }
                // Skip empty follow packs (no p-tags)
                $pTags = array_filter($event->getTags(), fn ($t) => ($t[0] ?? '') === 'p');
                if (empty($pTags)) {
                    continue;
                }
                $editorial[] = [
                    'kind'       => $event->getKind(),
                    'title'      => $event->getTitle() ?? 'Untitled',
                    'summary'    => $event->getSummary(),
                    'slug'       => $slug,
                    'pubkey'     => $event->getPubkey(),
                    'image'      => $event->getImage(),
                    'created_at' => $event->getCreatedAt(),
                ];
            }

            // Fetch curation sets (kinds 30004, 30005, 30006)
            $curatedSets = $repo->createQueryBuilder('e')
                ->where('e.kind IN (:kinds)')
                ->setParameter('kinds', [
                    KindsEnum::CURATION_SET->value,
                    KindsEnum::CURATION_VIDEOS->value,
                    KindsEnum::CURATION_PICTURES->value,
                ])
                ->orderBy('e.created_at', 'DESC')
                ->setMaxResults(50)
                ->getQuery()
                ->getResult();
            foreach ($curatedSets as $event) {
                // Only include curation sets from editor users
                if (!in_array($event->getPubkey(), $editorPubkeys, true)) {
                    continue;
                }
                $slug = $event->getSlug();
                if (!$slug) {
                    continue;
                }
                // Skip empty curation sets (no a- or e-tags)
                $contentTags = array_filter($event->getTags(), fn ($t) => in_array($t[0] ?? '', ['a', 'e'], true));
                if (empty($contentTags)) {
                    continue;
                }
                $editorial[] = [
                    'kind'       => $event->getKind(),
                    'title'      => $event->getTitle() ?? 'Untitled',
                    'summary'    => $event->getSummary(),
                    'slug'       => $slug,
                    'pubkey'     => $event->getPubkey(),
                    'image'      => $event->getImage(),
                    'created_at' => $event->getCreatedAt(),
                ];
            }

            // Deduplicate by pubkey:slug — keep only the latest entry per coordinate
            $seen = [];
            $deduped = [];
            foreach ($editorial as $item) {
                $key = ($item['pubkey'] ?? '') . ':' . ($item['slug'] ?? '');
                if (!isset($seen[$key]) || $item['created_at'] > $seen[$key]) {
                    $seen[$key] = $item['created_at'];
                    $deduped[$key] = $item;
                }
            }
            $editorial = array_values($deduped);

            // Sort: items with created_at > 0 come first (newest first); graph magazines trail at the end
            usort($editorial, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        } catch (\Throwable) {
            // Non-critical — proceed with empty editorial
        }

        // ── Featured Writers Tab (same feed as /featured-articles) ─────────
        $featuredArticles = [];
        $featuredAuthorsMetadata = [];
        try {
            $featuredFeed = $this->buildFeaturedWritersFeed($userRepository, $articleRepository, $redisCacheService);
            $featuredArticles = $featuredFeed['articles'];
            $featuredAuthorsMetadata = $featuredFeed['authorsMetadata'];
        } catch (\Throwable) {
            // Non-critical — proceed with empty featured writers feed
        }

        // Build main topics key => display name map from ForumTopics constant
        $mainTopicsMap = [];
        foreach (ForumTopics::TOPICS as $key => $data) {
            $name = $data['name'] ?? ucfirst($key);
            $mainTopicsMap[$key] = $name;
        }

        // Check if the logged-in user has a known interests list (kind 10015)
        // and fetch their named interest sets (kind 30015)
        $hasInterests = false;
        $interestSets = [];
        if ($user !== null) {
            try {
                $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                $interests = $nostrClient->getUserInterests($pubkeyHex);
                $hasInterests = !empty($interests);
                $interestSets = $nostrClient->getUserInterestSets($pubkeyHex);
            } catch (\Throwable) {
                // Non-critical — proceed without interests
            }
        }

        return $this->render('pages/discover.html.twig', [
            'highlights' => $highlights,
            'editorial' => $editorial,
            'featuredArticles' => $featuredArticles,
            'featuredAuthorsMetadata' => $featuredAuthorsMetadata,
            'mainTopicsMap' => $mainTopicsMap,
            'hasInterests' => $hasInterests,
            'interestSets' => $interestSets,
        ]);
    }

    /**
     * Turbo Frame tab endpoint for the discover page.
     */
    #[Route('/discover/tab/{tab}', name: 'discover_tab', requirements: ['tab' => 'recent'])]
    public function discoverTab(
        string $tab,
        RedisViewStore $viewStore,
        LatestArticlesExclusionPolicy $exclusionPolicy,
        RedisCacheService $redisCacheService,
        ContentSearchService $contentSearch,
        UserMuteListService $userMuteListService,
    ): Response
    {
        return match ($tab) {
            'recent' => $this->discoverRecentTab($viewStore, $exclusionPolicy, $redisCacheService, $contentSearch, $userMuteListService),
        };
    }

    /**
     * Serves the "Recent" tab for /discover from the cached latest articles list.
     * Redis fast path (view:articles:latest) with database fallback.
     */
    private function discoverRecentTab(
        RedisViewStore $viewStore,
        LatestArticlesExclusionPolicy $exclusionPolicy,
        RedisCacheService $redisCacheService,
        ContentSearchService $contentSearch,
        UserMuteListService $userMuteListService,
    ): Response
    {
        // ── Resolve user-level mute list (kind 10000, NIP-51) ──
        $userMutedPubkeys = [];
        $user = $this->getUser();
        if ($user) {
            try {
                $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                $userMutedPubkeys = $userMuteListService->getMutedPubkeys($pubkeyHex);
            } catch (\Throwable) {
                // Non-critical — proceed without user mutes
            }
        }

        // Fast path: Try Redis cache first (view:articles:latest)
        $cachedView = $viewStore->fetchLatestArticles();

        if ($cachedView !== null) {
            $articles = [];
            $authorsMetadata = [];

            foreach ($cachedView as $baseObject) {
                if (isset($baseObject['profiles'])) {
                    foreach ($baseObject['profiles'] as $pubkey => $profile) {
                        $authorsMetadata[$pubkey] = (object) $profile;
                    }
                }
            }

            foreach ($cachedView as $baseObject) {
                if (!isset($baseObject['article'])) {
                    continue;
                }

                $articlePayload = $baseObject['article'];
                $articlePubkey = $articlePayload['pubkey'] ?? null;

                if ($articlePubkey && in_array($articlePubkey, $userMutedPubkeys, true)) {
                    continue;
                }

                $authorMetadata = $articlePubkey ? ($authorsMetadata[$articlePubkey] ?? null) : null;

                if ($exclusionPolicy->shouldExcludeArticleData($articlePayload, $authorMetadata)) {
                    continue;
                }

                if (empty($articlePayload['slug']) || empty($articlePayload['title'])) {
                    continue;
                }

                $articles[] = (object) $articlePayload;
            }

            $authorsMetadataStd = $authorsMetadata; // already stdClass from cache
        } else {
            // Cache miss: fall back to database search (fast, non-blocking).
            // The cron job (app:cache-latest-articles, every 15 min) will repopulate Redis.
            $excludedPubkeys = array_values(array_unique(array_merge(
                $exclusionPolicy->getAllExcludedPubkeys(),
                $userMutedPubkeys,
            )));

            $articles = $contentSearch->getLatest(50, $excludedPubkeys);

            $authorPubkeys = [];
            foreach ($articles as $article) {
                $pk = $article->getPubkey();
                if ($pk && NostrKeyUtil::isHexPubkey($pk)) {
                    $authorPubkeys[] = $pk;
                }
            }
            $authorPubkeys = array_unique($authorPubkeys);
            $metaRaw = $redisCacheService->getMultipleMetadata($authorPubkeys);
            $authorsMetadataStd = [];
            foreach ($metaRaw as $pk => $m) {
                $authorsMetadataStd[$pk] = $m instanceof UserMetadata ? $m->toStdClass() : $m;
            }

            $articles = array_values(array_filter($articles, function (Article $article) use ($authorsMetadataStd, $exclusionPolicy): bool {
                $authorMetadata = $authorsMetadataStd[$article->getPubkey()] ?? null;

                return !$exclusionPolicy->shouldExclude($article, $authorMetadata);
            }));
        }

        return $this->render('discover/tabs/_recent.html.twig', [
            'articles' => $articles,
            'authorsMetadata' => $authorsMetadataStd,
        ]);
    }


    /**
     * @throws Exception|InvalidArgumentException
     */
    #[Route('/latest-articles', name: 'latest_articles')]
    public function latestArticles(
        RedisCacheService $redisCacheService,
        RedisViewStore $viewStore,
        LatestArticlesExclusionPolicy $exclusionPolicy,
        ArticleSearchFactory $articleSearchFactory,
        UserMuteListService $userMuteListService,
    ): Response
    {
        // ── Resolve user-level mute list (kind 10000, NIP-51) ──
        $userMutedPubkeys = [];
        $user = $this->getUser();
        if ($user) {
            try {
                $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                $userMutedPubkeys = $userMuteListService->getMutedPubkeys($pubkeyHex);
            } catch (\Throwable) {
                // Non-critical — proceed without user mutes
            }
        }

        // Unified exclusion: config-level deny-list + admin-muted + user-muted
        $excludedPubkeys = array_values(array_unique(array_merge(
            $exclusionPolicy->getAllExcludedPubkeys(),
            $userMutedPubkeys,
        )));

        // Fast path: Try Redis cache first (single GET - super fast!)
        $cachedView = $viewStore->fetchLatestArticles();

        if ($cachedView !== null) {
            $articles = [];
            $authorsMetadata = [];

            foreach ($cachedView as $baseObject) {
                if (isset($baseObject['profiles'])) {
                    foreach ($baseObject['profiles'] as $pubkey => $profile) {
                        $authorsMetadata[$pubkey] = (object) $profile;
                    }
                }
            }

            foreach ($cachedView as $baseObject) {
                if (!isset($baseObject['article'])) {
                    continue;
                }

                $articlePayload = $baseObject['article'];
                $articlePubkey = $articlePayload['pubkey'] ?? null;

                if ($articlePubkey && in_array($articlePubkey, $userMutedPubkeys, true)) {
                    continue;
                }

                $authorMetadata = $articlePubkey ? ($authorsMetadata[$articlePubkey] ?? null) : null;

                if ($exclusionPolicy->shouldExcludeArticleData($articlePayload, $authorMetadata)) {
                    continue;
                }

                if (empty($articlePayload['slug']) || empty($articlePayload['title'])) {
                    continue;
                }

                $articles[] = (object) $articlePayload;
            }
        } else {
            // Cache miss: fall back to database search (fast, non-blocking).
            // The cron job (app:cache-latest-articles, every 15 min) will
            // repopulate Redis.
            $articleSearch = $articleSearchFactory->create();
            $articles = $articleSearch->findLatest(50, $excludedPubkeys);

            // Collect author pubkeys for metadata (findLatest returns Article[])
            $authorPubkeys = [];
            foreach ($articles as $article) {
                $pk = $article->getPubkey();
                if ($pk && NostrKeyUtil::isHexPubkey($pk)) {
                    $authorPubkeys[] = $pk;
                }
            }
            $authorPubkeys = array_unique($authorPubkeys);
            $authorsMetadata = $redisCacheService->getMultipleMetadata($authorPubkeys);

            // Re-apply the shared policy after author metadata has been resolved.
            $articles = array_values(array_filter($articles, function (Article $article) use ($authorsMetadata, $exclusionPolicy): bool {
                $authorMetadata = $authorsMetadata[$article->getPubkey()] ?? null;

                return !$exclusionPolicy->shouldExclude($article, $authorMetadata);
            }));
        }

        // Convert UserMetadata objects to stdClass for template compatibility
        $authorsMetadataStd = [];
        foreach ($authorsMetadata as $pubkey => $metadata) {
            if ($metadata instanceof UserMetadata) {
                $authorsMetadataStd[$pubkey] = $metadata->toStdClass();
            } else {
                $authorsMetadataStd[$pubkey] = $metadata;
            }
        }

        return $this->render('pages/latest-articles.html.twig', [
            'articles' => $articles,
            'newsBots' => array_slice($excludedPubkeys, 0, 4),
            'authorsMetadata' => $authorsMetadataStd,
        ]);
    }

    /**
     * Latest articles from featured writers (ROLE_FEATURED_WRITER).
     */
    #[Route('/featured-articles', name: 'featured_articles')]
    public function featuredArticles(
        UserEntityRepository $userRepository,
        ArticleRepository $articleRepository,
        RedisCacheService $redisCacheService,
    ): Response
    {
        $featuredFeed = $this->buildFeaturedWritersFeed($userRepository, $articleRepository, $redisCacheService);

        return $this->render('pages/featured-articles.html.twig', [
            'articles' => $featuredFeed['articles'],
            'authorsMetadata' => $featuredFeed['authorsMetadata'],
        ]);
    }

    /**
     * @return array{articles: array<int, Article>, authorsMetadata: array<string, object>}
     */
    private function buildFeaturedWritersFeed(
        UserEntityRepository $userRepository,
        ArticleRepository $articleRepository,
        RedisCacheService $redisCacheService,
    ): array
    {
        $featuredUsers = $userRepository->findFeaturedWriters();

        // Convert featured writer npubs to hex pubkeys for article filtering.
        $pubkeys = [];
        foreach ($featuredUsers as $user) {
            $npub = $user->getNpub();
            if (NostrKeyUtil::isNpub($npub)) {
                $pubkeys[] = NostrKeyUtil::npubToHex($npub);
            }
        }

        $articles = $articleRepository->findLatestByPubkeys($pubkeys, 50);
        $authorPubkeys = array_unique(array_map(fn($article) => $article->getPubkey(), $articles));
        $metadataMap = $redisCacheService->getMultipleMetadata($authorPubkeys);

        $authorsMetadataStd = [];
        foreach ($metadataMap as $pubkey => $metadata) {
            $authorsMetadataStd[$pubkey] = $metadata instanceof UserMetadata
                ? $metadata->toStdClass()
                : $metadata;
        }

        return [
            'articles' => $articles,
            'authorsMetadata' => $authorsMetadataStd,
        ];
    }

    /**
     * Follow Pack view page — shows cover image, member list, and latest articles.
     * @throws InvalidArgumentException
     */
    #[Route('/follow-pack/{npub}/{dtag}', name: 'follow_pack_view', requirements: ['npub' => 'npub1[a-z0-9]+'])]
    public function followPackView(
        string $npub,
        string $dtag,
        Request $request,
        EntityManagerInterface $em,
        RedisCacheService $redisCacheService,
        ArticleRepository $articleRepository,
        MessageBusInterface $messageBus,
    ): Response
    {
        try {
            $pubkey = NostrKeyUtil::npubToHex($npub);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Invalid npub.');
        }

        $eventRepo = $em->getRepository(Event::class);
        $packEvent = $eventRepo->findByNaddr(KindsEnum::FOLLOW_PACK->value, $pubkey, $dtag);

        if (!$packEvent) {
            throw $this->createNotFoundException('Follow pack not found.');
        }

        // Extract tags
        $title = '';
        $image = null;
        $description = null;
        $memberPubkeys = [];

        foreach ($packEvent->getTags() as $tag) {
            $key = $tag[0] ?? '';
            match ($key) {
                'title' => $title = $tag[1] ?? '',
                'image' => $image = $tag[1] ?? null,
                'picture' => $image ??= $tag[1] ?? null,
                'description' => $description = $tag[1] ?? null,
                'about' => $description ??= $tag[1] ?? null,
                'p' => $memberPubkeys[] = $tag[1] ?? '',
                default => null,
            };
        }

        $memberPubkeys = array_filter(array_unique($memberPubkeys));

        // Resolve member profiles
        $nip19 = new Nip19Helper();
        $members = [];
        $metadataMap = $redisCacheService->getMultipleMetadata($memberPubkeys);
        $missingProfilePubkeys = [];

        foreach ($memberPubkeys as $hex) {
            $meta = $metadataMap[$hex] ?? null;
            $std = $meta ? ($meta instanceof UserMetadata ? $meta->toStdClass() : $meta) : null;

            if (!$std || (empty($std->name ?? '') && empty($std->display_name ?? ''))) {
                $missingProfilePubkeys[] = $hex;
            }

            $members[] = [
                'pubkey' => $hex,
                'npub' => $nip19->encodeNpub($hex),
                'displayName' => $std->display_name ?? $std->name ?? '',
                'name' => $std->name ?? '',
                'picture' => $std->picture ?? '',
                'nip05' => is_array($std->nip05 ?? '') ? ($std->nip05[0] ?? '') : ($std->nip05 ?? ''),
            ];
        }

        // Dispatch async batch profile fetch for members with missing metadata
        if (!empty($missingProfilePubkeys)) {
            try {
                // $messageBus->dispatch(new BatchUpdateProfileProjectionMessage($missingProfilePubkeys));
            } catch (\Throwable) {
                // Non-critical — profiles will be fetched on next refresh cycle
            }
        }

        // Fetch all deduplicated articles from pack members (revisions collapsed)
        $allArticles = $articleRepository->findAllByPubkeysDeduplicated($memberPubkeys);

        // Paginate
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        $pager = new Pagerfanta(new ArrayAdapter($allArticles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage(min($page, max(1, $pager->getNbPages())));

        $articles = array_slice($allArticles, ($pager->getCurrentPage() - 1) * $perPage, $perPage);

        // Author metadata for cards
        $articlePubkeys = array_unique(array_map(fn($a) => $a->getPubkey(), $articles));
        $articleMetaMap = $redisCacheService->getMultipleMetadata($articlePubkeys);
        $authorsMetadataStd = [];
        foreach ($articleMetaMap as $pk => $meta) {
            $authorsMetadataStd[$pk] = $meta instanceof UserMetadata
                ? $meta->toStdClass() : $meta;
        }

        // Resolve pack author profile
        $authorMeta = $redisCacheService->getMultipleMetadata([$pubkey]);
        $authorProfile = null;
        if (isset($authorMeta[$pubkey])) {
            $authorProfile = $authorMeta[$pubkey] instanceof UserMetadata
                ? $authorMeta[$pubkey]->toStdClass() : $authorMeta[$pubkey];
        }

        return $this->render('pages/follow-pack.html.twig', [
            'packEvent' => $packEvent,
            'title' => $title ?: 'Follow Pack',
            'image' => $image,
            'description' => $description,
            'members' => $members,
            'articles' => $articles,
            'authorsMetadata' => $authorsMetadataStd,
            'packDtag' => $dtag,
            'authorNpub' => $nip19->encodeNpub($pubkey),
            'authorPubkey' => $pubkey,
            'authorProfile' => $authorProfile,
            'pager' => $pager,
        ]);
    }

    #[Route('/follow-packs', name: 'follow_packs')]
    public function followPacks(): Response
    {
        return $this->render('pages/follow-packs.html.twig');
    }

    /**
     * @throws Exception
     */
    #[Route('/lists', name: 'lists')]
    public function lists(): Response
    {
        return $this->render('pages/lists.html.twig');
    }

    /**
     * Magazine front page: title, summary, category links, featured list.
     * @throws InvalidArgumentException|\Doctrine\DBAL\Exception
     */
    #[Route('/mag/{mag}', name: 'magazine-index')]
    public function magIndex(string $mag, EntityManagerInterface $entityManager) : Response
    {
        $eventRepository = $entityManager->getRepository(Event::class);
        if (!$eventRepository instanceof EventRepository) {
            throw $this->createNotFoundException('Magazine not found');
        }

        $magazine = $this->findLatestMagazineIndexBySlug($mag, $eventRepository);
        if ($magazine === null) {
            throw $this->createNotFoundException('Magazine not found');
        }

        $structure = $this->parseMagazineStructure($magazine);
        $frontPageArticleCoordinate = $structure['frontPageArticleCoordinate'];

        // Check if current user owns this magazine
        $isOwner = false;
        $user = $this->getUser();
        if ($user) {
            try {
                $key = new \swentel\nostr\Key\Key();
                $currentPubkey = $key->convertToHex($user->getUserIdentifier());
                $isOwner = ($currentPubkey === $magazine->getPubkey());
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // If a top-level article is designated, render only the front-article view.
        // The heavy article lookup/render is deferred to a lazy Turbo Frame route.
        if ($frontPageArticleCoordinate !== null) {
            return $this->render('magazine/magazine-front-article.html.twig', [
                'magazine' => $magazine,
                'mag' => $mag,
                'isOwner' => $isOwner,
            ]);
        }

        return $this->render('magazine/magazine-front.html.twig', [
            'magazine' => $magazine,
            'mag' => $mag,
            'isOwner' => $isOwner,
        ]);
    }

    #[Route('/mag/{mag}/front-article-frame', name: 'magazine-front-article-frame')]
    public function magFrontArticleFrame(
        string $mag,
        EntityManagerInterface $entityManager,
        Converter $converter,
        ContentSearchService $contentSearch,
        RedisCacheService $redisCacheService,
    ): Response {
        $eventRepository = $entityManager->getRepository(Event::class);
        if (!$eventRepository instanceof EventRepository) {
            return $this->render('magazine/_front_article_frame.html.twig', [
                'article' => null,
            ]);
        }

        $magazine = $this->findLatestMagazineIndexBySlug($mag, $eventRepository);
        if (!$magazine instanceof Event) {
            return $this->render('magazine/_front_article_frame.html.twig', [
                'article' => null,
            ]);
        }

        $structure = $this->parseMagazineStructure($magazine);
        $frontPageArticleCoordinate = $structure['frontPageArticleCoordinate'];
        if (!is_string($frontPageArticleCoordinate) || $frontPageArticleCoordinate === '') {
            return $this->render('magazine/_front_article_frame.html.twig', [
                'article' => null,
            ]);
        }

        $parts = explode(':', $frontPageArticleCoordinate, 3);
        $articleSlug = $parts[2] ?? '';
        $articlePubkey = $parts[1] ?? '';
        if ($articleSlug === '' || $articlePubkey === '') {
            return $this->render('magazine/_front_article_frame.html.twig', [
                'article' => null,
            ]);
        }

        $frontArticles = $contentSearch->findBySlugs([$articleSlug], 10);
        $frontArticle = null;
        foreach ($frontArticles as $candidate) {
            if ($candidate->getPubkey() === $articlePubkey) {
                $frontArticle = $candidate;
                break;
            }
        }
        if ($frontArticle === null && !empty($frontArticles)) {
            $frontArticle = $frontArticles[0];
        }

        if (!$frontArticle instanceof Article) {
            return $this->render('magazine/_front_article_frame.html.twig', [
                'article' => null,
            ]);
        }

        $htmlContent = $frontArticle->getProcessedHtml();
        if (!$htmlContent) {
            try {
                $htmlContent = $converter->convertToHTML(
                    $frontArticle->getContent(),
                    null,
                    $frontArticle->getKind()?->value,
                    $frontArticle->getRaw()['tags'] ?? null,
                );
            } catch (\Throwable) {
                $htmlContent = '';
            }
        }

        $key = new Key();
        $fpNpub = $key->convertPublicKeyToBech32($frontArticle->getPubkey());
        $fpAuthorMetadata = $redisCacheService->getMetadata($frontArticle->getPubkey());

        return $this->render('magazine/_front_article_frame.html.twig', [
            'article' => $frontArticle,
            'content' => $htmlContent,
            'npub' => $fpNpub,
            'author' => $fpAuthorMetadata->toStdClass(),
        ]);
    }

    #[Route('/mag/{mag}/chapters-frame', name: 'magazine-chapters-frame')]
    public function magChaptersFrame(string $mag, EntityManagerInterface $entityManager): Response
    {
        $eventRepository = $entityManager->getRepository(Event::class);
        if (!$eventRepository instanceof EventRepository) {
            return $this->render('magazine/_chapters_frame.html.twig', ['mag' => $mag, 'chapters' => []]);
        }

        $magazine = $this->findLatestMagazineIndexBySlug($mag, $eventRepository);
        if ($magazine === null) {
            return $this->render('magazine/_chapters_frame.html.twig', ['mag' => $mag, 'chapters' => []]);
        }

        $structure = $this->parseMagazineStructure($magazine);
        $chapters = $this->resolveMagazineChapters($structure['chapterCoordinates'], $eventRepository);

        return $this->render('magazine/_chapters_frame.html.twig', [
            'mag' => $mag,
            'chapters' => $chapters,
        ]);
    }

    #[Route('/mag/{mag}/categories-frame', name: 'magazine-categories-frame')]
    public function magCategoriesFrame(
        string $mag,
        EntityManagerInterface $entityManager,
        CacheItemPoolInterface $cache,
    ): Response
    {
        $eventRepository = $entityManager->getRepository(Event::class);
        if (!$eventRepository instanceof EventRepository) {
            return $this->render('magazine/_categories_frame.html.twig', ['mag' => $mag, 'previews' => []]);
        }

        $magazine = $this->findLatestMagazineIndexBySlug($mag, $eventRepository);
        if ($magazine === null) {
            return $this->render('magazine/_categories_frame.html.twig', ['mag' => $mag, 'previews' => []]);
        }

        $structure = $this->parseMagazineStructure($magazine);

        $cacheKey = 'magazine_category_previews_' . $magazine->getId();
        $cacheItem = $cache->getItem($cacheKey);
        if ($cacheItem->isHit() && is_array($cacheItem->get())) {
            $previewPayload = $cacheItem->get();
        } else {
            $previewPayload = $this->buildCategoryPreviewPayload($structure['categoryTags'], $eventRepository);
            $cacheItem->set($previewPayload);
            $cacheItem->expiresAfter(600);
            $cache->save($cacheItem);
        }

        $parsedCoordinates = [];
        foreach ($previewPayload as $preview) {
            $coordinate = $preview['articleCoordinate'] ?? null;
            if (!is_string($coordinate) || $coordinate === '') {
                continue;
            }

            $parts = explode(':', $coordinate, 3);
            if (count($parts) !== 3) {
                continue;
            }

            $parsedCoordinates[] = [
                'kind' => (int) $parts[0],
                'pubkey' => $parts[1],
                'slug' => $parts[2],
            ];
        }

        $articleMap = [];
        if ($parsedCoordinates !== []) {
            /** @var ArticleRepository $articleRepository */
            $articleRepository = $entityManager->getRepository(Article::class);
            $articleMap = $articleRepository->findByCoordinates($parsedCoordinates);
        }

        $previews = [];
        foreach ($previewPayload as $preview) {
            $coordinate = $preview['articleCoordinate'] ?? null;
            $article = null;
            if (is_string($coordinate) && $coordinate !== '') {
                $parts = explode(':', $coordinate, 3);
                if (count($parts) === 3) {
                    $key = ((int) $parts[0]) . ':' . $parts[1] . ':' . $parts[2];
                    $article = $articleMap[$key] ?? null;
                }
            }

            $previews[] = [
                'categorySlug' => $preview['categorySlug'] ?? '',
                'categoryTitle' => $preview['categoryTitle'] ?? '',
                'article' => $article,
            ];
        }

        return $this->render('magazine/_categories_frame.html.twig', [
            'mag' => $mag,
            'previews' => $previews,
        ]);
    }

    /**
     * Display all chapters in sequence like an ebook
     */
    #[Route('/mag/{mag}/read', name: 'magazine-read')]
    public function magRead(
        string $mag,
        EntityManagerInterface $entityManager,
        Converter $converter,
        CacheItemPoolInterface $articlesCache,
        LoggerInterface $logger
    ): Response
    {
        $eventRepository = $entityManager->getRepository(Event::class);
        if (!$eventRepository instanceof EventRepository) {
            throw $this->createNotFoundException('Magazine not found');
        }

        $magazine = $this->findLatestMagazineIndexBySlug($mag, $eventRepository);
        if ($magazine === null) {
            throw $this->createNotFoundException('Magazine not found');
        }

        // Extract chapter coordinates from magazine
        $chapterCoordinates = [];
        if ($magazine->getTags()) {
            foreach ($magazine->getTags() as $tag) {
                if (isset($tag[0]) && $tag[0] === 'a' && isset($tag[1])) {
                    $parts = explode(':', $tag[1], 3);
                    if (count($parts) === 3) {
                        $kind = (int)$parts[0];
                        if ($kind === KindsEnum::PUBLICATION_CONTENT->value) {
                            $chapterCoordinates[] = $tag[1];
                        }
                    }
                }
            }
        }

        // Fetch and process all chapters
        $chapterMap = $eventRepository->findByCoordinates($chapterCoordinates);
        $chapters = [];
        foreach ($chapterCoordinates as $coordinate) {
            $parts = explode(':', $coordinate, 3);
            if (count($parts) === 3) {
                $kind = (int)$parts[0];
                $pubkey = $parts[1];
                $slug = $parts[2];

                $chapter = $chapterMap[$coordinate] ?? null;

                if ($chapter instanceof Event) {
                    // Chapter exists - process content

                    // Process AsciiDoc content with caching
                    $cacheKey = 'chapter_' . $chapter->getId();
                    $cacheItem = $articlesCache->getItem($cacheKey);
                    $chapterHtml = null;
                    if ($cacheItem->isHit()) {
                        $chapterHtml = $cacheItem->get();
                    } else {
                        try {
                            $chapterHtml = $converter->convertAsciiDocToHTML($chapter->getContent());
                            $cacheItem->set($chapterHtml);
                            $articlesCache->save($cacheItem);
                        } catch (\Exception $e) {
                            $logger->error('Failed to convert chapter content', [
                                'chapter_id' => $chapter->getId(),
                                'error' => $e->getMessage()
                            ]);
                            $chapterHtml = '<pre>' . htmlspecialchars($chapter->getContent()) . '</pre>';
                        }
                    }

                    $chapters[] = [
                        'event' => $chapter,
                        'content' => $chapterHtml,
                        'coordinate' => $coordinate,
                        'fetched' => true,
                    ];
                } else {
                    // Chapter not fetched yet - placeholder
                    $chapters[] = [
                        'event' => null,
                        'coordinate' => $coordinate,
                        'slug' => $slug,
                        'pubkey' => $pubkey,
                        'kind' => $kind,
                        'fetched' => false,
                    ];
                }
            }
        }

        return $this->render('magazine/read.html.twig', [
            'magazine' => $magazine,
            'mag' => $mag,
            'chapters' => $chapters,
        ]);
    }

    /**
     * Display a single chapter (30041) within a magazine
     */
    #[Route('/mag/{mag}/chapter/{slug}', name: 'magazine-chapter', requirements: ['slug' => '.+'])]
    public function magChapter(
        string $mag,
        string $slug,
        EntityManagerInterface $entityManager,
        RedisCacheService $redisCacheService,
        Converter $converter,
        CacheItemPoolInterface $articlesCache,
        LoggerInterface $logger
    ): Response
    {
        $magazine = $redisCacheService->getMagazineIndex($mag);

        // Decode slug if it's URL encoded
        $slug = urldecode($slug);

        // Find the chapter by slug and kind
        $sql = "SELECT e.* FROM event e
                WHERE e.tags @> ?::jsonb
                AND e.kind = ?
                ORDER BY e.created_at DESC
                LIMIT 1";

        $conn = $entityManager->getConnection();
        $result = $conn->executeQuery($sql, [
            json_encode([['d', $slug]]),
            KindsEnum::PUBLICATION_CONTENT->value
        ]);

        $eventData = $result->fetchAssociative();

        if (!$eventData) {
            return $this->render('pages/article_not_found.html.twig', [
                'message' => 'The chapter could not be found.',
                'searchQuery' => $slug
            ]);
        }

        // Create Event entity
        $chapter = new Event();
        $chapter->setId($eventData['id']);
        $chapter->setKind((int)$eventData['kind']);
        $chapter->setPubkey($eventData['pubkey']);
        $chapter->setContent($eventData['content']);
        $chapter->setCreatedAt((int)$eventData['created_at']);
        $chapter->setTags(json_decode($eventData['tags'], true) ?? []);
        $chapter->setSig($eventData['sig']);

        // Process AsciiDoc content to HTML with caching
        // Kind 30041 (PUBLICATION_CONTENT) uses AsciiDoc by spec
        $cacheKey = 'chapter_' . $chapter->getId();
        $cacheItem = $articlesCache->getItem($cacheKey);
        $chapterHtml = null;
        if ($cacheItem->isHit()) {
            $chapterHtml = $cacheItem->get();
        } else {
            try {
                // Force AsciiDoc parsing for kind 30041
                $chapterHtml = $converter->convertAsciiDocToHTML($chapter->getContent());
                $cacheItem->set($chapterHtml);
                $articlesCache->save($cacheItem);
            } catch (\Exception $e) {
                $logger->error('Failed to convert chapter content', [
                    'chapter_id' => $chapter->getId(),
                    'error' => $e->getMessage()
                ]);
                // Fallback to raw content wrapped in <pre> — not cached so it retries next time
                $chapterHtml = '<pre>' . htmlspecialchars($chapter->getContent()) . '</pre>';
            }
        }

        // Get author metadata
        $key = new Key();
        $npub = $key->convertPublicKeyToBech32($chapter->getPubkey());
        $authorMetadata = $redisCacheService->getMetadata($chapter->getPubkey());
        $author = $authorMetadata->toStdClass();

        return $this->render('magazine/chapter.html.twig', [
            'magazine' => $magazine,
            'mag' => $mag,
            'chapter' => $chapter,
            'content' => $chapterHtml,
            'author' => $author,
            'npub' => $npub,
        ]);
    }

    /**
     * Magazine manifest - exposes complete magazine structure as JSON
     * @throws InvalidArgumentException
     */
    #[Route('/mag/{mag}/manifest.json', name: 'magazine-manifest')]
    public function magManifest(
        string $mag,
        EntityManagerInterface $entityManager,
        RedisCacheService $redisCacheService,
        LoggerInterface $logger
    ): JsonResponse
    {
        try {
            $eventRepository = $entityManager->getRepository(Event::class);
            if (!$eventRepository instanceof EventRepository) {
                return new JsonResponse(['error' => 'Magazine not found'], 404);
            }

            $magazine = $this->findLatestMagazineIndexBySlug($mag, $eventRepository);
            if ($magazine === null) {
                return new JsonResponse(['error' => 'Magazine not found'], 404);
            }

            // Build manifest structure
            $manifest = [
                '@context' => 'https://schema.org',
                '@type' => 'Periodical',
                'version' => '1.0',
                'generatedAt' => (new \DateTime())->format('c'),
                'magazine' => [
                    'id' => $magazine->getId(),
                    'slug' => $magazine->getSlug(),
                    'title' => $magazine->getTitle(),
                    'summary' => $magazine->getSummary(),
                    'image' => $magazine->getImage(),
                    'language' => $magazine->getLanguage(),
                    'pubkey' => $magazine->getPubkey(),
                    'createdAt' => (new \DateTime())->setTimestamp($magazine->getCreatedAt())->format('c'),
                    'url' => $this->generateUrl('magazine-index', ['mag' => $mag], 0),
                ],
                'categories' => [],
                'chapters' => [],
            ];

            // Extract category tags (30040) and chapter coordinates (30041) from magazine
            $categoryTags = [];
            $chapterCoordinates = [];
            if ($magazine->getTags()) {
                foreach ($magazine->getTags() as $tag) {
                    if (isset($tag[0]) && $tag[0] === 'a' && isset($tag[1])) {
                        $parts = explode(':', $tag[1], 3);
                        if (count($parts) === 3) {
                            $kind = (int)$parts[0];
                            if ($kind === KindsEnum::PUBLICATION_INDEX->value) {
                                $categoryTags[] = $tag;
                            } elseif ($kind === KindsEnum::PUBLICATION_CONTENT->value) {
                                $chapterCoordinates[] = $tag[1];
                            }
                        }
                    }
                }
            }

            $logger->info('Found category tags in magazine', [
                'magazine' => $mag,
                'categoryTags' => $categoryTags
            ]);

            // Build each category with its articles
            foreach ($categoryTags as $catTag) {
                // Parse coordinate from tag[1]: "kind:pubkey:d-identifier"
                $coordinate = $catTag[1] ?? null;
                if (!$coordinate) {
                    continue;
                }

                $parts = explode(':', $coordinate, 3);
                if (count($parts) !== 3) {
                    continue;
                }

                $catSlug = $parts[2]; // The d-identifier is the category slug

                // Fetch category details
                $sql = "SELECT e.* FROM event e
                        WHERE e.tags @> ?::jsonb
                        ORDER BY e.created_at DESC
                        LIMIT 1";

                $conn = $entityManager->getConnection();
                $result = $conn->executeQuery($sql, [
                    json_encode([['d', $catSlug]])
                ]);

                $eventData = $result->fetchAssociative();
                if (!$eventData) {
                    continue;
                }

                // Create Event entity from database result
                $category = new Event();
                $category->setId($eventData['id']);
                $category->setEventId($eventData['event_id'] ?? $eventData['id']);
                $category->setKind((int)$eventData['kind']);
                $category->setPubkey($eventData['pubkey']);
                $category->setContent($eventData['content']);
                $category->setCreatedAt((int)$eventData['created_at']);
                $category->setTags(json_decode($eventData['tags'], true) ?? []);
                $category->setSig($eventData['sig']);

                $categoryArticles = [];

                // Get articles for this category
                try {
                    $coordinates = [];
                    foreach ($category->getTags() as $tag) {
                        if ($tag[0] === 'a' && isset($tag[1])) {
                            $coordinates[] = $tag[1]; // Store full coordinate (kind:pubkey:slug)
                        }
                    }

                    if (!empty($coordinates)) {
                        // Query database directly for each coordinate
                        $articleRepo = $entityManager->getRepository(Article::class);

                        foreach ($coordinates as $coord) {
                            $parts = explode(':', $coord, 3);
                            if (count($parts) !== 3) {
                                continue;
                            }

                            [$kind, $pubkey, $slug] = $parts;

                            // Find article by pubkey and slug
                            $article = $articleRepo->findOneBy(
                                ['pubkey' => $pubkey, 'slug' => $slug],
                                ['createdAt' => 'DESC']
                            );

                            if ($article) {
                                $categoryArticles[] = [
                                    'title' => $article->getTitle(),
                                    'slug' => $article->getSlug(),
                                    'summary' => $article->getSummary(),
                                    'image' => $article->getImage(),
                                    'pubkey' => $article->getPubkey(),
                                    'createdAt' => $article->getCreatedAt() ? $article->getCreatedAt()->format('c') : null,
                                    'publishedAt' => $article->getPublishedAt() ? $article->getPublishedAt()->format('c') : null,
                                    'topics' => $article->getTopics(),
                                    'url' => $this->generateUrl('magazine-category-article', [
                                        'slug' => $article->getSlug(),
                                        'cat' => $catSlug,
                                        'mag' => $mag
                                    ], 0),
                                ];
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $logger->warning('Failed to fetch articles for category in manifest', [
                        'category' => $catSlug,
                        'error' => $e->getMessage()
                    ]);
                }

                $manifest['categories'][] = [
                    'slug' => $catSlug,
                    'title' => $category->getTitle(),
                    'summary' => $category->getSummary(),
                    'image' => $category->getImage(),
                    'url' => $this->generateUrl('magazine-category', [
                        'mag' => $mag,
                        'slug' => $catSlug
                    ], 0),
                    'articleCount' => count($categoryArticles),
                    'articles' => $categoryArticles,
                ];
            }

            // Build each chapter from coordinates
            foreach ($chapterCoordinates as $coordinate) {
                $parts = explode(':', $coordinate, 3);
                if (count($parts) !== 3) {
                    continue;
                }

                $chapterSlug = $parts[2];

                // Fetch chapter details
                $sql = "SELECT e.* FROM event e
                        WHERE e.tags @> ?::jsonb
                        AND e.kind = ?
                        ORDER BY e.created_at DESC
                        LIMIT 1";

                $conn = $entityManager->getConnection();
                $result = $conn->executeQuery($sql, [
                    json_encode([['d', $chapterSlug]]),
                    KindsEnum::PUBLICATION_CONTENT->value
                ]);

                $eventData = $result->fetchAssociative();
                if (!$eventData) {
                    continue;
                }

                // Create Event entity from database result
                $chapter = new Event();
                $chapter->setId($eventData['id']);
                $chapter->setEventId($eventData['event_id']);
                $chapter->setKind((int)$eventData['kind']);
                $chapter->setPubkey($eventData['pubkey']);
                $chapter->setContent($eventData['content']);
                $chapter->setCreatedAt((int)$eventData['created_at']);
                $chapter->setTags(json_decode($eventData['tags'], true) ?? []);
                $chapter->setSig($eventData['sig']);

                $manifest['chapters'][] = [
                    'slug' => $chapterSlug,
                    'title' => $chapter->getTitle(),
                    'summary' => $chapter->getSummary(),
                    'image' => $chapter->getImage(),
                    'createdAt' => (new \DateTime())->setTimestamp($chapter->getCreatedAt())->format('c'),
                    'url' => $this->generateUrl('magazine-chapter', [
                        'mag' => $mag,
                        'slug' => $chapterSlug
                    ], 0),
                ];
            }

            // Add metadata
            $manifest['stats'] = [
                'totalCategories' => count($manifest['categories']),
                'totalArticles' => array_sum(array_column($manifest['categories'], 'articleCount')),
                'totalChapters' => count($manifest['chapters']),
            ];

            return new JsonResponse($manifest, 200, [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'public, max-age=300', // Cache for 5 minutes
            ]);

        } catch (\Exception $e) {
            $logger->error('Failed to generate magazine manifest', [
                'magazine' => $mag,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new JsonResponse([
                'error' => 'Failed to generate manifest',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/mag/{mag}/cat/{slug}', name: 'magazine-category')]
    public function magCategory($mag, $slug, EntityManagerInterface $entityManager,
                                RedisCacheService $redisCacheService,
                                LoggerInterface $logger,
                                ContentSearchService $contentSearch,
                                MessageBusInterface $messageBus): Response
    {
        $magazine = $redisCacheService->getMagazineIndex($mag);

        $conn = $entityManager->getConnection();
        // Fast path: indexed replaceable lookup (kind + d_tag), newest wins.
        $eventData = $conn->executeQuery(
            'SELECT e.* FROM event e
             WHERE e.kind = :kind
               AND e.d_tag = :slug
             ORDER BY e.created_at DESC
             LIMIT 1',
            [
                'kind' => KindsEnum::PUBLICATION_INDEX->value,
                'slug' => $slug,
            ]
        )->fetchAssociative();

        if ($eventData === false) {
            // Backward compatibility for pre-d_tag rows.
            $eventData = $conn->executeQuery(
                "SELECT e.* FROM event e
                 WHERE e.kind = :kind
                   AND EXISTS (
                     SELECT 1 FROM jsonb_array_elements(e.tags) AS tag
                     WHERE tag->>0 = 'd' AND tag->>1 = :slug
                   )
                 ORDER BY e.created_at DESC
                 LIMIT 1",
                [
                    'kind' => KindsEnum::PUBLICATION_INDEX->value,
                    'slug' => $slug,
                ]
            )->fetchAssociative();
        }


        if ($eventData === false) {
            throw new Exception('Category not found');
        }

        $tags = json_decode($eventData['tags'], true);

        $list = [];
        $coordinates = []; // Store full coordinates (kind:author:slug)
        $category = [];

        // Extract category metadata and article coordinates
        foreach ($tags as $tag) {
            if ($tag[0] === 'title') {
                $category['title'] = $tag[1];
            }
            if ($tag[0] === 'summary') {
                $category['summary'] = $tag[1];
            }
            if ($tag[0] === 'a') {
                $coordinates[] = $tag[1]; // Store the full coordinate
            }
        }

        // Detect whether this category references 30041 chapters, 30040 subcategories, or articles
        $isChapterCategory = false;
        $isSubcategoryCategory = false;
        if (!empty($coordinates)) {
            $firstParts = explode(':', $coordinates[0], 3);
            $firstKind = (int)($firstParts[0] ?? 0);
            $isChapterCategory = ($firstKind === KindsEnum::PUBLICATION_CONTENT->value);
            $isSubcategoryCategory = ($firstKind === KindsEnum::PUBLICATION_INDEX->value);
        }

        if ($isSubcategoryCategory) {
            // This category's children are other 30040 index events — render as subcategory list
            $subcategoryTags = array_map(fn(string $c) => ['a', $c], $coordinates);

            $catIndex = new \swentel\nostr\Event\Event();
            $catIndex->setId($eventData['id']);
            $catIndex->setPublicKey($eventData['pubkey']);
            $catIndex->setCreatedAt($eventData['created_at']);
            $catIndex->setKind($eventData['kind']);
            $catIndex->setTags($tags);
            $catIndex->setContent($eventData['content']);
            $catIndex->setSignature($eventData['sig']);

            return $this->render('pages/category-subcategories.html.twig', [
                'mag' => $mag,
                'magazine' => $magazine,
                'category' => $category,
                'subcategoryTags' => $subcategoryTags,
                'index' => $catIndex,
            ]);
        }

        if ($isChapterCategory) {
            /** @var EventRepository $eventRepository */
            $eventRepository = $entityManager->getRepository(Event::class);
            $chapterMap = $eventRepository->findByCoordinates($coordinates);

            // Fetch 30041 chapter events directly
            $chapters = [];
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', $coordinate, 3);
                if (count($parts) !== 3) {
                    continue;
                }
                $chapterKind = (int) ($parts[0] ?? 0);
                $chapterPubkey = $parts[1] ?? '';
                $chapterSlug = $parts[2];
                $chapter = $chapterMap[$coordinate] ?? null;

                if (!$chapter instanceof Event && $chapterKind === KindsEnum::PUBLICATION_CONTENT->value && $chapterPubkey !== '' && $chapterSlug !== '') {
                    // Fallback for legacy rows that may not have d_tag backfilled.
                    $chapterData = $conn->executeQuery(
                        "SELECT e.* FROM event e
                         WHERE e.kind = :kind
                           AND e.pubkey = :pubkey
                           AND (
                             e.d_tag = :slug
                             OR EXISTS (
                               SELECT 1 FROM jsonb_array_elements(e.tags) AS tag
                               WHERE tag->>0 = 'd' AND tag->>1 = :slug
                             )
                           )
                         ORDER BY e.created_at DESC
                         LIMIT 1",
                        [
                            'kind' => KindsEnum::PUBLICATION_CONTENT->value,
                            'pubkey' => $chapterPubkey,
                            'slug' => $chapterSlug,
                        ]
                    )->fetchAssociative();

                    if ($chapterData !== false) {
                        $chapter = $this->hydrateEventFromRow($chapterData);
                    }
                }

                if ($chapter instanceof Event) {
                    $chapters[] = [
                        'event' => $chapter,
                        'coordinate' => $coordinate,
                        'slug' => $chapterSlug,
                        'fetched' => true,
                    ];
                } else {
                    $chapters[] = [
                        'event' => null,
                        'coordinate' => $coordinate,
                        'slug' => $chapterSlug,
                        'pubkey' => $parts[1],
                        'kind' => (int)$parts[0],
                        'fetched' => false,
                    ];
                }
            }

            // Create a proper Event object for template compatibility
            $catIndex = new \swentel\nostr\Event\Event();
            $catIndex->setId($eventData['id']);
            $catIndex->setPublicKey($eventData['pubkey']);
            $catIndex->setCreatedAt($eventData['created_at']);
            $catIndex->setKind($eventData['kind']);
            $catIndex->setTags($tags);
            $catIndex->setContent($eventData['content']);
            $catIndex->setSignature($eventData['sig']);

            return $this->render('pages/category-chapters.html.twig', [
                'mag' => $mag,
                'magazine' => $magazine,
                'chapters' => $chapters,
                'category' => $category,
                'index' => $catIndex,
            ]);
        }

        if (!empty($coordinates)) {
            // Parse full coordinates (kind:pubkey:slug) so we can look up articles
            // by their exact NIP-01 replaceable-event address rather than by slug
            // alone — slugs collide across authors and kinds.
            $parsed = [];
            $slugs = [];
            $wikiCoordinates = [];
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', $coordinate, 3);
                if (count($parts) !== 3 || $parts[2] === '') {
                    continue;
                }
                $kind = (int) $parts[0];
                $parsed[$coordinate] = [
                    'kind'   => $kind,
                    'pubkey' => $parts[1],
                    'slug'   => $parts[2],
                ];
                $slugs[] = $parts[2];

                if ($kind === KindsEnum::WIKI->value) {
                    $wikiCoordinates[] = $coordinate;
                }
            }

            // Preferred path: look up by full coordinate (kind, pubkey, slug).
            $articleRepo = $entityManager->getRepository(\App\Entity\Article::class);
            $coordMap = $articleRepo->findByCoordinates(array_values($parsed));
            $eventRepo = $entityManager->getRepository(Event::class);
            $wikiMap = [];
            foreach ($wikiCoordinates as $wikiCoordinate) {
                $wikiInfo = $parsed[$wikiCoordinate] ?? null;
                if (!is_array($wikiInfo)) {
                    continue;
                }

                $wikiEvent = $eventRepo->findByNaddr(KindsEnum::WIKI->value, $wikiInfo['pubkey'], $wikiInfo['slug']);
                if ($wikiEvent instanceof Event) {
                    $wikiMap[$wikiCoordinate] = $this->buildMagazineWikiCard($wikiEvent, $wikiCoordinate);
                }
            }

            // Fallback: search service lookup by slug, for any coordinate we
            // couldn't resolve by exact match (e.g. articles not yet projected
            // into the DB article table, or minor kind mismatches from legacy
            // data). Dedupe by slug, newest wins.
            $slugMap = [];
            $needSlugFallback = false;
            foreach ($parsed as $coord => $info) {
                $key = $info['kind'] . ':' . $info['pubkey'] . ':' . $info['slug'];
                if (!isset($coordMap[$key])) {
                    $needSlugFallback = true;
                    break;
                }
            }
            if ($needSlugFallback) {
                $articles = $contentSearch->findBySlugs(array_values(array_unique($slugs)), 200);
                foreach ($articles as $item) {
                    $slug = $item->getSlug();
                    if ($slug === '' || $slug === null) {
                        continue;
                    }
                    if (!isset($slugMap[$slug]) || $item->getCreatedAt() > $slugMap[$slug]->getCreatedAt()) {
                        $slugMap[$slug] = $item;
                    }
                }
            }

            // Build ordered list in original coordinate order.
            $missingCoordinates = [];
            $queuedMissingWikiCoordinates = [];
            foreach ($coordinates as $coordinate) {
                if (!isset($parsed[$coordinate])) {
                    continue;
                }
                $info = $parsed[$coordinate];
                $key = $info['kind'] . ':' . $info['pubkey'] . ':' . $info['slug'];

                if ($info['kind'] === KindsEnum::WIKI->value) {
                    if (isset($wikiMap[$coordinate])) {
                        $list[] = $wikiMap[$coordinate];
                    } else {
                        $missingCoordinates[] = $coordinate;

                        // Queue only wiki coordinates that this category actually references.
                        $lookupKey = sprintf('naddr:%d:%s:%s', $info['kind'], $info['pubkey'], $info['slug']);
                        $messageBus->dispatch(new FetchEventFromRelaysMessage(
                            lookupKey: $lookupKey,
                            type: 'naddr',
                            kind: $info['kind'],
                            pubkey: $info['pubkey'],
                            identifier: $info['slug'],
                        ));
                        $queuedMissingWikiCoordinates[] = $coordinate;
                    }
                    continue;
                }

                if (isset($coordMap[$key])) {
                    $list[] = $coordMap[$key];
                } elseif (isset($slugMap[$info['slug']])) {
                    $list[] = $slugMap[$info['slug']];
                } else {
                    $missingCoordinates[] = $coordinate;
                }
            }

            if (!empty($missingCoordinates)) {
                $logger->info('There were missing articles', [
                    'missing' => $missingCoordinates
                ]);
            }

            if (!empty($queuedMissingWikiCoordinates)) {
                $logger->info('Queued missing magazine wiki coordinates for async hydration', [
                    'coordinates' => $queuedMissingWikiCoordinates,
                ]);
            }
        }

        // Create a proper Event object for template compatibility
        $catIndex = new \swentel\nostr\Event\Event();
        $catIndex->setId($eventData['id']);
        $catIndex->setPublicKey($eventData['pubkey']);
        $catIndex->setCreatedAt($eventData['created_at']);
        $catIndex->setKind($eventData['kind']);
        $catIndex->setTags($tags);
        $catIndex->setContent($eventData['content']);
        $catIndex->setSignature($eventData['sig']);

        return $this->render('pages/category.html.twig', [
            'mag' => $mag,
            'magazine' => $magazine,
            'list' => $list,
            'category' => $category,
            'index' => $catIndex
        ]);
    }

    #[Route('/mag/{mag}/cat/{cat}/wiki/{slug}', name: 'magazine-category-wiki', requirements: ['slug' => '.+'])]
    public function magCategoryWiki(
        string $mag,
        string $cat,
        string $slug,
        RedisCacheService $redisCacheService,
        EntityManagerInterface $entityManager,
        Converter $converter,
        LoggerInterface $logger,
    ): Response {
        $magazine = $redisCacheService->getMagazineIndex($mag);
        $slug = urldecode($slug);

        $conn = $entityManager->getConnection();
        $categoryData = $conn->executeQuery(
            'SELECT e.* FROM event e
             WHERE e.kind = :kind
               AND e.d_tag = :slug
             ORDER BY e.created_at DESC
             LIMIT 1',
            [
                'kind' => KindsEnum::PUBLICATION_INDEX->value,
                'slug' => $cat,
            ]
        )->fetchAssociative();

        if ($categoryData === false) {
            $categoryData = $conn->executeQuery(
                "SELECT e.* FROM event e
                 WHERE e.kind = :kind
                   AND EXISTS (
                     SELECT 1 FROM jsonb_array_elements(e.tags) AS tag
                     WHERE tag->>0 = 'd' AND tag->>1 = :slug
                   )
                 ORDER BY e.created_at DESC
                 LIMIT 1",
                [
                    'kind' => KindsEnum::PUBLICATION_INDEX->value,
                    'slug' => $cat,
                ]
            )->fetchAssociative();
        }

        if ($categoryData === false) {
            throw $this->createNotFoundException('Category not found');
        }

        $categoryEvent = new Event();
        $categoryEvent->setId($categoryData['id']);
        $categoryEvent->setKind((int) $categoryData['kind']);
        $categoryEvent->setPubkey($categoryData['pubkey']);
        $categoryEvent->setContent($categoryData['content']);
        $categoryEvent->setCreatedAt((int) $categoryData['created_at']);
        $categoryEvent->setTags(json_decode($categoryData['tags'], true) ?? []);
        $categoryEvent->setSig($categoryData['sig']);

        $wikiPubkey = null;
        foreach ($categoryEvent->getTags() as $tag) {
            if (!is_array($tag) || ($tag[0] ?? null) !== 'a' || !isset($tag[1]) || !is_string($tag[1])) {
                continue;
            }

            $parts = explode(':', $tag[1], 3);
            if (count($parts) !== 3) {
                continue;
            }

            if ((int) $parts[0] === KindsEnum::WIKI->value && $parts[2] === $slug) {
                $wikiPubkey = $parts[1];
                break;
            }
        }

        if ($wikiPubkey === null) {
            throw $this->createNotFoundException('Wiki not found in this category');
        }

        $wiki = $entityManager->getRepository(Event::class)->findByNaddr(KindsEnum::WIKI->value, $wikiPubkey, $slug);
        if (!$wiki instanceof Event) {
            return $this->render('pages/article_not_found.html.twig', [
                'message' => 'The wiki could not be found.',
                'searchQuery' => $slug,
            ]);
        }

        try {
            $content = $converter->convertToHTML(
                $wiki->getContent(),
                null,
                $wiki->getKind(),
                $wiki->getTags(),
            );
        } catch (\Throwable $e) {
            $logger->error('Failed to convert wiki content to HTML', [
                'coordinate' => sprintf('%d:%s:%s', KindsEnum::WIKI->value, $wikiPubkey, $slug),
                'error' => $e->getMessage(),
            ]);
            $content = nl2br(htmlspecialchars($wiki->getContent(), ENT_QUOTES, 'UTF-8'));
        }

        try {
            $key = new Key();
            $npub = $key->convertPublicKeyToBech32($wiki->getPubkey());
        } catch (\Throwable) {
            $npub = $wiki->getPubkey();
        }
        $author = $redisCacheService->getMetadata($wiki->getPubkey())->toStdClass();

        return $this->render('magazine/wiki.html.twig', [
            'mag' => $mag,
            'magazine' => $magazine,
            'categorySlug' => $cat,
            'categoryTitle' => $categoryEvent->getTitle() ?? $cat,
            'wiki' => $wiki,
            'content' => $content,
            'author' => $author,
            'npub' => $npub,
        ]);
    }

    private function buildMagazineWikiCard(Event $wikiEvent, string $coordinate): object
    {
        return (object) [
            'pubkey' => $wikiEvent->getPubkey(),
            'slug' => $wikiEvent->getSlug() ?? '',
            'coordinate' => $coordinate,
            'kind' => $wikiEvent->getKind(),
            'title' => $wikiEvent->getTitle() ?: ($wikiEvent->getSlug() ?? 'Wiki'),
            'summary' => $wikiEvent->getSummary(),
            'image' => $wikiEvent->getImage(),
            'createdAt' => (new \DateTimeImmutable())->setTimestamp($wikiEvent->getCreatedAt()),
            'publishedAt' => null,
            'isMagazineWiki' => true,
        ];
    }


    /**
     * @throws InvalidArgumentException
     */
    #[Route('/mag/{mag}/cat/{cat}/d/{slug}', name: 'magazine-category-article', requirements: ['slug' => '.+'])]
    public function magArticle($mag, $cat, $slug,
                               RedisCacheService $redisCacheService,
                               CacheItemPoolInterface $articlesCache,
                               EntityManagerInterface $entityManager,
                               Converter $converter,
                               LoggerInterface $logger,
                               NostrEventParser $eventParser,
                               ReadingListNavigationService $readingListNavigation): Response
    {
        $magazine = $redisCacheService->getMagazineIndex($mag);

        $article = null;
        $repository = $entityManager->getRepository(Article::class);
        $slug = urldecode($slug);

        // Resolve the exact coordinate from the magazine category to avoid cross-pubkey collisions
        $pubkeyFromCoordinate = null;
        if ($magazine && method_exists($magazine, 'getTags')) {
            // Find the category coordinate matching $cat in the magazine index
            foreach ($magazine->getTags() as $tag) {
                if ($tag[0] === 'a' && isset($tag[1])) {
                    $parts = explode(':', $tag[1], 3);
                    if (count($parts) === 3 && $parts[2] === $cat) {
                        // Found the category coordinate, now fetch the category event
                        $catSlug = $parts[2];
                        $conn = $entityManager->getConnection();
                        $result = $conn->executeQuery(
                            "SELECT e.* FROM event e WHERE e.tags @> ?::jsonb ORDER BY e.created_at DESC LIMIT 1",
                            [json_encode([['d', $catSlug]])]
                        );
                        $eventData = $result->fetchAssociative();
                        if ($eventData) {
                            $catTags = json_decode($eventData['tags'], true) ?? [];
                            foreach ($catTags as $catTag) {
                                if ($catTag[0] === 'a' && isset($catTag[1])) {
                                    $coordParts = explode(':', $catTag[1], 3);
                                    if (count($coordParts) === 3 && $coordParts[2] === $slug) {
                                        $pubkeyFromCoordinate = $coordParts[1];
                                        break 2;
                                    }
                                }
                            }
                        }
                        break;
                    }
                }
            }
        }

        // Use exact coordinate (pubkey + slug) when available, fall back to slug-only lookup
        if ($pubkeyFromCoordinate) {
            $articles = $repository->findBy(['pubkey' => $pubkeyFromCoordinate, 'slug' => $slug]);
        } else {
            $articles = $repository->findBy(['slug' => $slug]);
        }

        $revisions = count($articles);

        if ($revisions === 0) {
            return $this->render('pages/article_not_found.html.twig', [
                'message' => 'The article could not be found.',
                'searchQuery' => $slug
            ]);
        }

        if ($revisions > 1) {
            usort($articles, function ($a, $b) {
                return $b->getCreatedAt() <=> $a->getCreatedAt();
            });
        }

        $article = $articles[0];

        // Use pre-processed HTML from database if available, otherwise convert on-the-fly
        $htmlContent = $article->getProcessedHtml();
        if (!$htmlContent) {
            $cacheKey = 'article_' . $article->getEventId();
            $cacheItem = $articlesCache->getItem($cacheKey);
            if ($cacheItem->isHit()) {
                $htmlContent = $cacheItem->get();
            } else {
                try {
                    $htmlContent = $converter->convertToHTML(
                        $article->getContent(),
                        null,
                        $article->getKind()?->value,
                        $article->getRaw()['tags'] ?? null,
                    );
                    $cacheItem->set($htmlContent);
                    $articlesCache->save($cacheItem);
                } catch (\Throwable $e) {
                    $logger->error('Failed to convert article content to HTML', [
                        'article_id' => $article->getId(),
                        'error' => $e->getMessage(),
                    ]);
                    // Render escaped content for this request only — don't cache so it retries next time.
                    $htmlContent = nl2br(htmlspecialchars($article->getContent(), ENT_QUOTES, 'UTF-8'));
                }
            }
        }

        $key = new Key();
        $npub = $key->convertPublicKeyToBech32($article->getPubkey());
        $authorMetadata = $redisCacheService->getMetadata($article->getPubkey());
        $author = $authorMetadata->toStdClass(); // Convert to stdClass for template compatibility

        // Parse advanced metadata from raw event for zap splits etc.
        $advancedMetadata = null;
        if ($article->getRaw()) {
            $tags = $article->getRaw()['tags'] ?? [];
            $advancedMetadata = $eventParser->parseAdvancedMetadata($tags);
        }

        // set canonical url to this article as article-slug path
        $canonical = $this->generateUrl('author-article-slug', [
            'npub' => $npub,
            'slug' => $article->getSlug()
        ], 0);

        // Find prev/next articles from reading lists
        $listNav = null;
        try {
            $navCoordinate = '30023:' . $article->getPubkey() . ':' . $article->getSlug();
            $listNav = $readingListNavigation->findNavigation($navCoordinate);
        } catch (\Exception $e) {}

        return $this->render('pages/article.html.twig', [
            'magazine' => $magazine,
            'mag' => $mag,
            'article' => $article,
            'author' => $author,
            'npub' => $npub,
            'content' => $htmlContent,
            'canEdit' => false,
            'canonical' => $canonical,
            'advancedMetadata' => $advancedMetadata,
            'listNav' => $listNav,
        ]);
    }


    /**
     * OG Preview endpoint for URLs
     */
    #[Route('/og-preview/', name: 'og_preview', methods: ['POST'])]
    public function ogPreview(RequestStack $requestStack): Response
    {
        $request = $requestStack->getCurrentRequest();
        $data = json_decode($request->getContent(), true);
        $url = $data['url'] ?? null;
        if (!$url) {
            return new Response('<div class="alert alert-warning">No URL provided.</div>', 400);
        }
        try {
            $embed = new \Embed\Embed();
            $info = $embed->get($url);
            if (!$info) {
                throw new \Exception('No OG data found');
            }
            return $this->render('components/Molecules/OgPreview.html.twig', [
                'og' => [
                    'title' => $info->title,
                    'description' => $info->description,
                    'image' => $info->image,
                    'url' => $url
                ]
            ]);
        } catch (\Exception $e) {
            return new Response('<div class="alert alert-warning">Unable to load OG preview for ' . htmlspecialchars($url) . '</div>', 200);
        }
    }

    /**
     * Nostr Preview endpoint for Nostr identifiers (naddr, nevent, note, npub, nprofile)
     */
    #[Route('/api/preview/', name: 'nostr_preview', methods: ['POST'])]
    public function nostrPreview(RequestStack $requestStack, EntityManagerInterface $entityManager, LoggerInterface $logger, RedisCacheService $redisCacheService): Response
    {
        $request = $requestStack->getCurrentRequest();
        $data = json_decode($request->getContent(), true);

        $identifier = $data['identifier'] ?? null;
        $type = $data['type'] ?? null;
        $decoded = $data['decoded'] ?? null;

        if (!$identifier || !$type) {
            return new Response('<div class="alert alert-warning">Invalid preview request.</div>', 400);
        }

        // If decoded is a JSON string, decode it to array
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        // Ensure decoded is an array
        if (!is_array($decoded)) {
            $logger->error('Decoded data is not an array', [
                'decoded' => $decoded,
                'type' => gettype($decoded)
            ]);
            return new Response('<div class="alert alert-warning">Invalid preview data format.</div>', 400);
        }

        try {
            // Handle different Nostr identifier types
            switch ($type) {
                case 'naddr':
                    return $this->handleNaddrPreview($decoded, $entityManager, $logger);
                case 'nevent':
                case 'note':
                    return $this->handleEventPreview($decoded, $entityManager, $logger);
                case 'npub':
                case 'nprofile':
                    return $this->handleProfilePreview($decoded, $entityManager, $logger, $redisCacheService);
                default:
                    return new Response('<div class="alert alert-warning">Unsupported preview type: ' . htmlspecialchars($type) . '</div>', 200);
            }
        } catch (\Exception $e) {
            $logger->error('Error generating Nostr preview', [
                'identifier' => $identifier,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return new Response('<div class="alert alert-warning">Unable to load preview.</div>', 200);
        }
    }

    private function handleNaddrPreview(array $decoded, EntityManagerInterface $entityManager, LoggerInterface $logger): Response
    {
        $kind = $decoded['kind'] ?? null;
        $pubkey = $decoded['pubkey'] ?? null;
        $identifier = $decoded['identifier'] ?? null;

        if ($kind === KindsEnum::LONGFORM->value) {
            // Try to find article in database
            $repository = $entityManager->getRepository(Article::class);
            $article = $repository->findOneBy(['slug' => $identifier, 'pubkey' => $pubkey]);

            if ($article) {
                $key = new Key();
                $npub = $key->convertPublicKeyToBech32($article->getPubkey());

                return $this->render('components/Molecules/ArticlePreview.html.twig', [
                    'article' => $article,
                    'npub' => $npub
                ]);
            }

            // Article not in database yet - show a link to fetch it
            // We need to construct the naddr from the decoded data
            try {
                $relays = $decoded['relays'] ?? [];
                $naddr = \nostriphant\NIP19\Bech32::naddr(
                    kind: (int)$kind,
                    pubkey: $pubkey,
                    identifier: $identifier,
                    relays: $relays
                );

                return new Response(
                    '<div class="alert alert-info">
                        <strong>Article Preview</strong><br>
                        This article hasn\'t been fetched yet.
                        <a href="' . $this->generateUrl('article-naddr', ['naddr' => (string)$naddr]) . '" class="alert-link" data-turbo-frame="_top">Click here to view it</a>
                    </div>',
                    200
                );
            } catch (\Exception $e) {
                $logger->error('Failed to generate naddr for preview', ['error' => $e->getMessage()]);
                return new Response('<div class="alert alert-warning">Unable to generate article link.</div>', 200);
            }
        }

        return new Response('<div class="alert alert-info">Preview for kind ' . $kind . ' not yet supported.</div>', 200);
    }

    private function handleEventPreview(array $decoded, EntityManagerInterface $entityManager, LoggerInterface $logger): Response
    {
        $eventId = $decoded['id'] ?? ($decoded[0] ?? null);

        if (!$eventId) {
            return new Response('<div class="alert alert-warning">Invalid event identifier.</div>', 200);
        }

        // Try to find the event in database
        $event = $entityManager->getRepository(Event::class)->findOneBy(['eventId' => $eventId]);

        if ($event) {
            $content = $event->getPayload()['content'] ?? '';
            $kind = $event->getKind();

            return new Response(
                '<div class="nostr-event-preview p-3">' .
                '<span class="badge" style="background: var(--color-info, #17a2b8);">Kind: ' . (int)$kind . '</span> ' .
                '<p class="mt-2 mb-0 text-muted small line-clamp-3">' . htmlspecialchars(mb_substr($content, 0, 300)) . '</p>' .
                '</div>',
                200
            );
        }

        return new Response('<div class="alert alert-info">Event not found locally.</div>', 200);
    }

    private function handleProfilePreview(array $decoded, EntityManagerInterface $entityManager, LoggerInterface $logger, RedisCacheService $redisCacheService): Response
    {
        // decoded data contains hex pubkey directly or nested
        $pubkey = $decoded['pubkey'] ?? ($decoded[0] ?? null);

        if (!$pubkey || !NostrKeyUtil::isHexPubkey($pubkey)) {
            $logger->warning('Profile preview: invalid or missing pubkey', ['decoded' => $decoded]);
            if ($this->isGranted('ROLE_ADMIN')) {
                return new Response('<div class="alert alert-warning">Invalid profile identifier.</div>', 200);
            }
            return new Response('', 200);
        }

        try {
            $npub = NostrKeyUtil::hexToNpub($pubkey);
            $metadata = $redisCacheService->getMetadata($pubkey);
            $user = $metadata->toStdClass();

            return $this->render('components/Molecules/ProfilePreview.html.twig', [
                'user' => $user,
                'npub' => $npub,
                'pubkey' => $pubkey,
            ]);
        } catch (\Exception $e) {
            $logger->error('Failed to load profile preview', ['pubkey' => $pubkey, 'error' => $e->getMessage()]);
            $npub = NostrKeyUtil::hexToNpub($pubkey);
            return $this->render('components/Molecules/ProfilePreview.html.twig', [
                'user' => null,
                'npub' => $npub,
                'pubkey' => $pubkey,
            ]);
        }
    }
}
