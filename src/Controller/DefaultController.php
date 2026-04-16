<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\BatchUpdateProfileProjectionMessage;
use App\Repository\ArticleRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\Nostr\NostrEventParser;
use App\Service\ReadingListNavigationService;
use App\Service\Search\ArticleSearchFactory;
use App\Service\Search\ArticleSearchInterface;
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
use App\Service\UserMuteListService;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;

class DefaultController extends AbstractController
{
    /**
     * @throws Exception
     */
    #[Route('/', name: 'home')]
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
     * Bookshelf – lists all books (kind 30040 events referencing 30041 content)
     */
    #[Route('/bookshelf', name: 'bookshelf')]
    public function bookshelf(): Response
    {
        return $this->render('pages/bookshelf.html.twig');
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

        // Fast path: Try to get from Redis views first (single GET)
        $cachedView = $viewStore->fetchLatestArticles();

        if ($cachedView !== null) {
            // Redis view data already matches template expectations!
            // Just extract articles and profiles - NO MAPPING NEEDED
            $articles = [];
            $authorsMetadata = [];

            foreach ($cachedView as $baseObject) {
                if (isset($baseObject['article'])) {
                    // Skip articles from user-muted pubkeys
                    $articlePubkey = $baseObject['article']['pubkey'] ?? null;
                    if ($articlePubkey && in_array($articlePubkey, $userMutedPubkeys, true)) {
                        continue;
                    }
                    // Skip articles without slug or title (incomplete records)
                    if (empty($baseObject['article']['slug']) || empty($baseObject['article']['title'])) {
                        continue;
                    }
                    $articles[] = (object) $baseObject['article'];
                }
                if (isset($baseObject['profiles'])) {
                    foreach ($baseObject['profiles'] as $pubkey => $profile) {
                        $authorsMetadata[$pubkey] = (object) $profile;
                    }
                }
            }
        } else {
            // Cache miss: fall back to database search (fast, non-blocking).
            // The cron job (app:cache-latest-articles, every 15 min) will
            // repopulate Redis.
            $excludedPubkeys = array_values(array_unique(array_merge(
                $exclusionPolicy->getAllExcludedPubkeys(),
                $userMutedPubkeys,
            )));

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
        }

        // Convert UserMetadata objects to stdClass for template compatibility
        // (cached views already have stdClass, so only convert UserMetadata)
        $authorsMetadataStd = [];
        foreach ($authorsMetadata as $pubkey => $metadata) {
            if ($metadata instanceof UserMetadata) {
                $authorsMetadataStd[$pubkey] = $metadata->toStdClass();
            } else {
                $authorsMetadataStd[$pubkey] = $metadata;
            }
        }

        // Build main topics key => display name map from ForumTopics constant
        $mainTopicsMap = [];
        foreach (ForumTopics::TOPICS as $key => $data) {
            $name = $data['name'] ?? ucfirst($key);
            $mainTopicsMap[$key] = $name;
        }

        return $this->render('pages/discover.html.twig', [
            'articles' => $articles,
            'authorsMetadata' => $authorsMetadataStd,
            'mainTopicsMap' => $mainTopicsMap,
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
            // Use cached articles - already filtered and with metadata
            $articles = [];
            $authorsMetadata = [];

            foreach ($cachedView as $baseObject) {
                if (isset($baseObject['article'])) {
                    // Skip articles from user-muted pubkeys
                    $articlePubkey = $baseObject['article']['pubkey'] ?? null;
                    if ($articlePubkey && in_array($articlePubkey, $userMutedPubkeys, true)) {
                        continue;
                    }
                    // Skip articles without slug or title (incomplete records)
                    if (empty($baseObject['article']['slug']) || empty($baseObject['article']['title'])) {
                        continue;
                    }
                    $articles[] = (object) $baseObject['article'];
                }
                if (isset($baseObject['profiles'])) {
                    foreach ($baseObject['profiles'] as $pubkey => $profile) {
                        $authorsMetadata[$pubkey] = (object) $profile;
                    }
                }
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
        }

        // Convert UserMetadata objects to stdClass for template compatibility
        // (cached views already have stdClass, so only convert UserMetadata)
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
        \App\Repository\UserEntityRepository $userRepository,
        ArticleRepository $articleRepository,
        RedisCacheService $redisCacheService,
    ): Response
    {
        $featuredUsers = $userRepository->findFeaturedWriters();

        // Convert to hex pubkeys
        $pubkeys = [];
        foreach ($featuredUsers as $user) {
            $npub = $user->getNpub();
            if (NostrKeyUtil::isNpub($npub)) {
                $pubkeys[] = NostrKeyUtil::npubToHex($npub);
            }
        }

        $articles = $articleRepository->findLatestByPubkeys($pubkeys, 50);

        // Collect unique author pubkeys for metadata
        $authorPubkeys = array_unique(array_map(fn($a) => $a->getPubkey(), $articles));
        $metadataMap = $redisCacheService->getMultipleMetadata($authorPubkeys);

        $authorsMetadataStd = [];
        foreach ($metadataMap as $pk => $meta) {
            $authorsMetadataStd[$pk] = $meta instanceof UserMetadata
                ? $meta->toStdClass()
                : $meta;
        }

        return $this->render('pages/featured-articles.html.twig', [
            'articles' => $articles,
            'authorsMetadata' => $authorsMetadataStd,
        ]);
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
                $messageBus->dispatch(new BatchUpdateProfileProjectionMessage($missingProfilePubkeys));
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
        // Get latest magazine index by slug from database
        $nzines = $entityManager->getRepository(Event::class)->findBy(['kind' => KindsEnum::PUBLICATION_INDEX]);

        // Filter by slug
        $nzines = array_filter($nzines, function ($index) use ($mag) {
            return $index->getSlug() === $mag;
        });

        if (count($nzines) === 0) {
            throw $this->createNotFoundException('Magazine not found');
        }

        // Sort by createdAt, keep newest
        usort($nzines, function ($a, $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        $magazine = array_shift($nzines);

        // Extract 'a' tags and categorize them by kind (30040 vs 30041)
        $categoryTags = [];
        $chapterCoordinates = [];

        if ($magazine->getTags()) {
            foreach ($magazine->getTags() as $tag) {
                if (isset($tag[0]) && $tag[0] === 'a' && isset($tag[1])) {
                    $parts = explode(':', $tag[1], 3);
                    if (count($parts) === 3) {
                        $kind = (int)$parts[0];
                        if ($kind === KindsEnum::PUBLICATION_INDEX->value) {
                            // This is a category (30040)
                            $categoryTags[] = $tag;
                        } elseif ($kind === KindsEnum::PUBLICATION_CONTENT->value) {
                            // This is a chapter (30041)
                            $chapterCoordinates[] = $tag[1]; // Store the full coordinate
                        }
                    }
                }
            }
        }

        // Fetch chapter events and create placeholders for missing ones
        $chapters = [];
        if (!empty($chapterCoordinates)) {
            foreach ($chapterCoordinates as $coordinate) {
                $parts = explode(':', $coordinate, 3);
                if (count($parts) === 3) {
                    $kind = (int)$parts[0];
                    $pubkey = $parts[1];
                    $slug = $parts[2];

                    // Query for the chapter by d-tag (slug)
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
                    if ($eventData) {
                        // Chapter exists in database
                        $chapter = new Event();
                        $chapter->setId($eventData['id']);
                        $chapter->setKind((int)$eventData['kind']);
                        $chapter->setPubkey($eventData['pubkey']);
                        $chapter->setContent($eventData['content']);
                        $chapter->setCreatedAt((int)$eventData['created_at']);
                        $chapter->setTags(json_decode($eventData['tags'], true) ?? []);
                        $chapter->setSig($eventData['sig']);
                        $chapters[] = [
                            'event' => $chapter,
                            'coordinate' => $coordinate,
                            'fetched' => true,
                        ];
                    } else {
                        // Chapter not fetched yet - create placeholder
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
        }

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

        return $this->render('magazine/magazine-front.html.twig', [
            'magazine' => $magazine,
            'mag' => $mag,
            'categoryTags' => $categoryTags,
            'chapters' => $chapters,
            'isOwner' => $isOwner,
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
        // Get latest magazine index by slug from database (same as magIndex)
        $nzines = $entityManager->getRepository(Event::class)->findBy(['kind' => KindsEnum::PUBLICATION_INDEX]);

        // Filter by slug
        $nzines = array_filter($nzines, function ($index) use ($mag) {
            return $index->getSlug() === $mag;
        });

        if (count($nzines) === 0) {
            throw $this->createNotFoundException('Magazine not found');
        }

        // Sort by createdAt, keep newest
        usort($nzines, function ($a, $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        $magazine = array_shift($nzines);

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
        $chapters = [];
        foreach ($chapterCoordinates as $coordinate) {
            $parts = explode(':', $coordinate, 3);
            if (count($parts) === 3) {
                $kind = (int)$parts[0];
                $pubkey = $parts[1];
                $slug = $parts[2];

                // Query for the chapter by d-tag (slug)
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

                if ($eventData) {
                    // Chapter exists - create event and process content
                    $chapter = new Event();
                    $chapter->setId($eventData['id']);
                    $chapter->setEventId($eventData['event_id']);
                    $chapter->setKind((int)$eventData['kind']);
                    $chapter->setPubkey($eventData['pubkey']);
                    $chapter->setContent($eventData['content']);
                    $chapter->setCreatedAt((int)$eventData['created_at']);
                    $chapter->setTags(json_decode($eventData['tags'], true) ?? []);
                    $chapter->setSig($eventData['sig']);

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
            // Get latest magazine index by slug from database
            $nzines = $entityManager->getRepository(Event::class)->findBy(['kind' => KindsEnum::PUBLICATION_INDEX]);

            // Filter by slug
            $nzines = array_filter($nzines, function ($index) use ($mag) {
                return $index->getSlug() === $mag;
            });

            if (count($nzines) === 0) {
                return new JsonResponse(['error' => 'Magazine not found'], 404);
            }

            // Sort by createdAt, keep newest
            usort($nzines, function ($a, $b) {
                return $b->getCreatedAt() <=> $a->getCreatedAt();
            });

            $magazine = array_shift($nzines);

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
                                ArticleSearchInterface $articleSearch): Response
    {
        $magazine = $redisCacheService->getMagazineIndex($mag);

        // Query the database for the category event by slug using native SQL
        $sql = "SELECT e.* FROM event e
                WHERE e.tags @> ?::jsonb
                ORDER BY e.created_at DESC
                ";

        $conn = $entityManager->getConnection();
        $result = $conn->executeQuery($sql, [
            json_encode([['d', $slug]])
        ]);

        $eventData = $result->fetchAssociative();


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

        // Detect whether this category references 30041 chapters or articles
        $isChapterCategory = false;
        if (!empty($coordinates)) {
            $firstParts = explode(':', $coordinates[0], 3);
            $firstKind = (int)($firstParts[0] ?? 0);
            $isChapterCategory = ($firstKind === KindsEnum::PUBLICATION_CONTENT->value);
        }

        if ($isChapterCategory) {
            // Fetch 30041 chapter events directly
            $chapters = [];
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', $coordinate, 3);
                if (count($parts) !== 3) {
                    continue;
                }
                $chapterSlug = $parts[2];

                $chapterSql = "SELECT e.* FROM event e
                    WHERE e.tags @> ?::jsonb
                    AND e.kind = ?
                    ORDER BY e.created_at DESC
                    LIMIT 1";

                $chapterResult = $conn->executeQuery($chapterSql, [
                    json_encode([['d', $chapterSlug]]),
                    KindsEnum::PUBLICATION_CONTENT->value,
                ]);

                $chapterData = $chapterResult->fetchAssociative();
                if ($chapterData) {
                    $chapter = new Event();
                    $chapter->setId($chapterData['id']);
                    $chapter->setKind((int)$chapterData['kind']);
                    $chapter->setPubkey($chapterData['pubkey']);
                    $chapter->setContent($chapterData['content']);
                    $chapter->setCreatedAt((int)$chapterData['created_at']);
                    $chapter->setTags(json_decode($chapterData['tags'], true) ?? []);
                    $chapter->setSig($chapterData['sig']);
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
            // Extract slugs for query
            $slugs = array_map(function($coordinate) {
                $parts = explode(':', $coordinate, 3);
                return end($parts);
            }, $coordinates);
            $slugs = array_filter($slugs); // Remove empty values

            // Use the search service to find articles by slugs
            $articles = $articleSearch->findBySlugs(array_values($slugs), 200);

            // Create a map of slug => item to remove duplicates
            $slugMap = [];
            foreach ($articles as $item) {
                $slug = $item->getSlug();
                if ($slug !== '') {
                    // If the slugMap doesn't contain it yet, add it
                    if (!isset($slugMap[$slug])) {
                        $slugMap[$slug] = $item;
                    } else {
                        // If it already exists, compare created_at timestamps and save newest
                        $existingItem = $slugMap[$slug];
                        if ($item->getCreatedAt() > $existingItem->getCreatedAt()) {
                            $slugMap[$slug] = $item;
                        }
                    }
                }
            }

            // Find missing coordinates
            $missingCoordinates = [];
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', $coordinate, 3);
                if (!isset($slugMap[end($parts)])) {
                    $missingCoordinates[] = $coordinate;
                }
            }

            // If we have missing articles, fetch them directly using NostrClient's getArticlesByCoordinates
            if (!empty($missingCoordinates)) {

                $logger->info('There were missing articles', [
                    'missing' => $missingCoordinates
                ]);
            }

            // Build ordered list based on original coordinates order
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', $coordinate, 3);
                if (isset($slugMap[end($parts)])) {
                    $list[] = $slugMap[end($parts)];
                }
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
    public function nostrPreview(RequestStack $requestStack, EntityManagerInterface $entityManager, LoggerInterface $logger): Response
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
                    return $this->handleProfilePreview($decoded, $entityManager, $logger);
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
        // For now, just show a basic preview
        return new Response('<div class="alert alert-info">Event preview coming soon.</div>', 200);
    }

    private function handleProfilePreview(array $decoded, EntityManagerInterface $entityManager, LoggerInterface $logger): Response
    {
        // For now, just show a basic preview
        return new Response('<div class="alert alert-info">Profile preview coming soon.</div>', 200);
    }
}
