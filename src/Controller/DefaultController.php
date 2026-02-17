<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\MutedPubkeysService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrEventParser;
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
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController extends AbstractController
{
    /**
     * @throws Exception
     */
    #[Route('/', name: 'home')]
    public function index(): Response
    {
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
     * Global magazines manifest - lists all available magazines
     */
    #[Route('/magazines/manifest.json', name: 'magazines-manifest')]
    public function magazinesManifest(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ): JsonResponse
    {
        try {
            // Get all magazine indices from database
            $nzines = $entityManager->getRepository(Event::class)->findBy(
                ['kind' => KindsEnum::PUBLICATION_INDEX],
                ['created_at' => 'DESC']
            );

            // Group by slug and keep only the latest version of each
            $magazinesBySlug = [];
            foreach ($nzines as $magazine) {
                $slug = $magazine->getSlug();
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
        CacheItemPoolInterface $articlesCache,
        MutedPubkeysService $mutedPubkeysService,
        ArticleSearchFactory $articleSearchFactory
    ): Response
    {
        // Fast path: Try to get from Redis views first (single GET)
        $cachedView = $viewStore->fetchLatestArticles();
        $fromCache = false;

        if ($cachedView !== null) {
            // Redis view data already matches template expectations!
            // Just extract articles and profiles - NO MAPPING NEEDED
            $articles = [];
            $authorsMetadata = [];

            foreach ($cachedView as $baseObject) {
                if (isset($baseObject['article'])) {
                    $articles[] = (object) $baseObject['article']; // Cast to object for template
                }
                if (isset($baseObject['profiles'])) {
                    foreach ($baseObject['profiles'] as $pubkey => $profile) {
                        $authorsMetadata[$pubkey] = (object) $profile; // Cast to object for template
                    }
                }
            }
            $fromCache = true;
        } else {
            // Fallback path: Use search service if Redis view not available
            $excludedPubkeys = $mutedPubkeysService->getMutedPubkeys();

            // Use the search factory to get the appropriate search service
            $articleSearch = $articleSearchFactory->create();
            $articles = $articleSearch->findLatest(50, $excludedPubkeys);

            // Fetch author metadata for fallback path
            $authorPubkeys = [];
            foreach ($articles as $article) {
                if ($article instanceof \App\Entity\Article) {
                    $pubkey = $article->getPubkey();
                    if ($pubkey && NostrKeyUtil::isHexPubkey($pubkey)) {
                        $authorPubkeys[] = $pubkey;
                    }
                } elseif (is_object($article)) {
                    if ($article->getPubkey() !== null && NostrKeyUtil::isHexPubkey($article->getPubkey())) {
                        $authorPubkeys[] = $article->getPubkey();
                    } elseif (isset($article->npub) && NostrKeyUtil::isNpub($article->npub)) {
                        $authorPubkeys[] = NostrKeyUtil::npubToHex($article->npub);
                    }
                }
            }
            $authorPubkeys = array_unique($authorPubkeys);
            $authorsMetadata = $redisCacheService->getMultipleMetadata($authorPubkeys);
        }

        // Convert UserMetadata objects to stdClass for template compatibility
        // (cached views already have stdClass, so only convert UserMetadata)
        $authorsMetadataStd = [];
        foreach ($authorsMetadata as $pubkey => $metadata) {
            if ($metadata instanceof \App\Dto\UserMetadata) {
                $authorsMetadataStd[$pubkey] = $metadata->toStdClass();
            } else {
                // Already stdClass from cached view
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
            'from_redis_view' => $fromCache,
        ]);
    }

    /**
     * @throws Exception|InvalidArgumentException
     */
    #[Route('/latest-articles', name: 'latest_articles')]
    public function latestArticles(
        RedisCacheService $redisCacheService,
        NostrClient $nostrClient,
        RedisViewStore $viewStore,
        MutedPubkeysService $mutedPubkeysService
    ): Response
    {
        // Get muted pubkeys from database/cache
        $excludedPubkeys = $mutedPubkeysService->getMutedPubkeys();

        // Fast path: Try Redis cache first (single GET - super fast!)
        $cachedView = $viewStore->fetchLatestArticles();

        if ($cachedView !== null) {
            // Use cached articles - already filtered and with metadata
            $articles = [];
            $authorsMetadata = [];

            foreach ($cachedView as $baseObject) {
                if (isset($baseObject['article'])) {
                    $articles[] = (object) $baseObject['article'];
                }
                if (isset($baseObject['profiles'])) {
                    foreach ($baseObject['profiles'] as $pubkey => $profile) {
                        $authorsMetadata[$pubkey] = (object) $profile;
                    }
                }
            }

            $fromCache = true;
        } else {
            // Cache miss: Fetch fresh from relay (slower but ensures we have data)
            set_time_limit(300);
            ini_set('max_execution_time', '300');


            try {
                // Fetch fresh from relay
                $articles = $nostrClient->getLatestLongFormArticles(50);

                // Filter out excluded pubkeys
                $articles = array_filter($articles, function($article) use ($excludedPubkeys) {
                    if (method_exists($article, 'getPubkey')) {
                        return !in_array($article->getPubkey(), $excludedPubkeys, true);
                    }
                    return true;
                });

                // Collect author pubkeys for metadata
                $authorPubkeys = [];
                foreach ($articles as $article) {
                    if (method_exists($article, 'getPubkey')) {
                        $authorPubkeys[] = $article->getPubkey();
                    } elseif (isset($article->pubkey) && NostrKeyUtil::isHexPubkey($article->pubkey)) {
                        $authorPubkeys[] = $article->pubkey;
                    }
                }
                $authorPubkeys = array_unique($authorPubkeys);
                $authorsMetadata = $redisCacheService->getMultipleMetadata($authorPubkeys);

                $fromCache = false;
            } catch (\Exception $e) {
                // Relay fetch failed and no cache available
                throw $e;
            }
        }

        // Convert UserMetadata objects to stdClass for template compatibility
        // (cached views already have stdClass, so only convert UserMetadata)
        $authorsMetadataStd = [];
        foreach ($authorsMetadata as $pubkey => $metadata) {
            if ($metadata instanceof \App\Dto\UserMetadata) {
                $authorsMetadataStd[$pubkey] = $metadata->toStdClass();
            } else {
                // Already stdClass from cached view
                $authorsMetadataStd[$pubkey] = $metadata;
            }
        }

        return $this->render('pages/latest-articles.html.twig', [
            'articles' => $articles,
            'newsBots' => array_slice($excludedPubkeys, 0, 4),
            'authorsMetadata' => $authorsMetadataStd,
            'from_redis_view' => $fromCache ?? false,
        ]);
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
     * @throws InvalidArgumentException
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
                            WHERE e.tags::jsonb @> ?::jsonb
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
                        $chapter->setEventId($eventData['event_id']);
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

        return $this->render('magazine/magazine-front.html.twig', [
            'magazine' => $magazine,
            'mag' => $mag,
            'categoryTags' => $categoryTags,
            'chapters' => $chapters,
        ]);
    }

    /**
     * Display all chapters in sequence like an ebook
     */
    #[Route('/mag/{mag}/read', name: 'magazine-read')]
    public function magRead(
        string $mag,
        EntityManagerInterface $entityManager,
        RedisCacheService $redisCacheService,
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
                        WHERE e.tags::jsonb @> ?::jsonb
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
                    if (!$cacheItem->isHit()) {
                        try {
                            $html = $converter->convertAsciiDocToHTML($chapter->getContent());
                            $cacheItem->set($html);
                            $articlesCache->save($cacheItem);
                        } catch (\Exception $e) {
                            $logger->error('Failed to convert chapter content', [
                                'chapter_id' => $chapter->getId(),
                                'error' => $e->getMessage()
                            ]);
                            $cacheItem->set('<pre>' . htmlspecialchars($chapter->getContent()) . '</pre>');
                            $articlesCache->save($cacheItem);
                        }
                    }

                    $chapters[] = [
                        'event' => $chapter,
                        'content' => $cacheItem->get(),
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
                WHERE e.tags::jsonb @> ?::jsonb
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
        $chapter->setEventId($eventData['event_id']);
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
        if (!$cacheItem->isHit()) {
            try {
                // Force AsciiDoc parsing for kind 30041
                $html = $converter->convertAsciiDocToHTML($chapter->getContent());
                $cacheItem->set($html);
                $articlesCache->save($cacheItem);
            } catch (\Exception $e) {
                $logger->error('Failed to convert chapter content', [
                    'chapter_id' => $chapter->getId(),
                    'error' => $e->getMessage()
                ]);
                // Fallback to raw content wrapped in <pre>
                $cacheItem->set('<pre>' . htmlspecialchars($chapter->getContent()) . '</pre>');
                $articlesCache->save($cacheItem);
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
            'content' => $cacheItem->get(),
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
                        WHERE e.tags::jsonb @> ?::jsonb
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
                $category->setEventId($eventData['event_id']);
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
                        WHERE e.tags::jsonb @> ?::jsonb
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
                WHERE e.tags::jsonb @> ?::jsonb
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

//                try {
//                    $nostrArticles = $nostrClient->getArticlesByCoordinates($missingCoordinates);
//
//                    foreach ($nostrArticles as $coordinate => $event) {
//                        $parts = explode(':', $coordinate);
//                        if (count($parts) === 3) {
//                            $article = $articleFactory->createFromLongFormContentEvent($event);
//                            // Save article to database for future queries
//                            $nostrClient->saveEachArticleToTheDatabase($article);
//                            // Add to the slugMap
//                            $slugMap[$article->getSlug()] = $article;
//                        }
//                    }
//                } catch (\Exception $e) {
//                    $logger->error('Error fetching missing articles', [
//                        'error' => $e->getMessage()
//                    ]);
//                }
            }

            // Build ordered list based on original coordinates order
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', $coordinate,3);
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
                               NostrEventParser $eventParser): Response
    {
        $magazine = $redisCacheService->getMagazineIndex($mag);

        $article = null;
        // check if an item with same eventId already exists in the db
        $repository = $entityManager->getRepository(Article::class);
        // slug might be url encoded, decode it
        $slug = urldecode($slug);
        $articles = $repository->findBy(['slug' => $slug]);

        $revisions = count($articles);

        if ($revisions === 0) {
            return $this->render('pages/article_not_found.html.twig', [
                'message' => 'The article could not be found.',
                'searchQuery' => $slug
            ]);
        }

        if ($revisions > 1) {
            // sort articles by created at date
            usort($articles, function ($a, $b) {
                return $b->getCreatedAt() <=> $a->getCreatedAt();
            });
        }

        $article = $articles[0];

        $cacheKey = 'article_' . $article->getEventId();
        $cacheItem = $articlesCache->getItem($cacheKey);
        if (!$cacheItem->isHit()) {
            $cacheItem->set($converter->convertToHTML($article->getContent()));
            $articlesCache->save($cacheItem);
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
        $canonical = $this->generateUrl('article-slug', [
            'slug' => $article->getSlug()
        ], 0);

        return $this->render('pages/article.html.twig', [
            'magazine' => $magazine,
            'mag' => $mag,
            'article' => $article,
            'author' => $author,
            'npub' => $npub,
            'content' => $cacheItem->get(),
            'canEdit' => false,
            'canonical' => $canonical,
            'advancedMetadata' => $advancedMetadata
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
    #[Route('/preview/', name: 'nostr_preview', methods: ['POST'])]
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
