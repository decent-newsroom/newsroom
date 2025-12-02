<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\NostrClient;
use App\Service\RedisCacheService;
use App\Util\CommonMark\Converter;
use App\Util\ForumTopics;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Elastica\Collapse;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\Terms;
use Exception;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Tests\Compiler\K;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

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
     * @throws Exception|InvalidArgumentException
     */
    #[Route('/discover', name: 'discover')]
    public function discover(
        FinderInterface $finder,
        RedisCacheService $redisCacheService,
        CacheItemPoolInterface $articlesCache
    ): Response
    {
        set_time_limit(300);
        ini_set('max_execution_time', '300');

        $env = $this->getParameter('kernel.environment');
        // Reuse previous latest list cache key to show same set as old 'latest'
        $cacheKey = 'latest_articles_list_' . $env;
        $cacheItem = $articlesCache->getItem($cacheKey);

        $key = new Key();
        $excludedPubkeys = [
            $key->convertToHex('npub1etsrcjz24fqewg4zmjze7t5q8c6rcwde5zdtdt4v3t3dz2navecscjjz94'),
            $key->convertToHex('npub1m7szwpud3jh2k3cqe73v0fd769uzsj6rzmddh4dw67y92sw22r3sk5m3ys'),
            $key->convertToHex('npub13wke9s6njrmugzpg6mqtvy2d49g4d6t390ng76dhxxgs9jn3f2jsmq82pk'),
            $key->convertToHex('npub10akm29ejpdns52ca082skmc3hr75wmv3ajv4987c9lgyrfynrmdqduqwlx'),
            $key->convertToHex('npub13uvnw9qehqkds68ds76c4nfcn3y99c2rl9z8tr0p34v7ntzsmmzspwhh99'),
            $key->convertToHex('npub1fls5au5fxj6qj0t36sage857cs4tgfpla0ll8prshlhstagejtkqc9s2yl'),
            $key->convertToHex('npub1t5d8kcn0hu8zmt6dpkgatd5hwhx76956g7qmdzwnca6fzgprzlhqnqks86'),
            $key->convertToHex('npub14l5xklll5vxzrf6hfkv8m6n2gqevythn5pqc6ezluespah0e8ars4279ss'),
        ];

        if (!$cacheItem->isHit()) {
            // Fallback: run query now if command hasn't populated cache yet
            $boolQuery = new BoolQuery();
            $boolQuery->addMustNot(new Query\Terms('pubkey', $excludedPubkeys));
            $query = new Query($boolQuery);
            $query->setSize(50);
            $query->setSort(['createdAt' => ['order' => 'desc']]);
            $collapseSlug = new Collapse();
            $collapseSlug->setFieldname('slug');
            $query->setCollapse($collapseSlug);
            $articles = $finder->find($query);
            $cacheItem->set($articles);
            $cacheItem->expiresAfter(3600); // 1 hour to match command cache duration
            $articlesCache->save($cacheItem);
        }

        $articles = $cacheItem->get();

        $authorPubkeys = [];
        foreach ($articles as $article) {
            if (isset($article->pubkey) && NostrKeyUtil::isHexPubkey($article->pubkey)) {
                $authorPubkeys[] = $article->pubkey;
            } elseif (isset($article->npub) && NostrKeyUtil::isNpub($article->npub)) {
                $authorPubkeys[] = NostrKeyUtil::npubToHex($article->npub);
            }
        }
        $authorPubkeys = array_unique($authorPubkeys);
        $authorsMetadata = $redisCacheService->getMultipleMetadata($authorPubkeys);

        // Build main topics key => display name map from ForumTopics constant
        $mainTopicsMap = [];
        foreach (ForumTopics::TOPICS as $key => $data) {
            $name = $data['name'] ?? ucfirst($key);
            $mainTopicsMap[$key] = $name;
        }

        return $this->render('pages/discover.html.twig', [
            'articles' => $articles,
            'authorsMetadata' => $authorsMetadata,
            'mainTopicsMap' => $mainTopicsMap,
        ]);
    }

    /**
     * @throws Exception
     */
    #[Route('/latest-articles', name: 'latest_articles')]
    public function latestArticles(
        RedisCacheService $redisCacheService,
        NostrClient $nostrClient
    ): Response
    {
        set_time_limit(300); // 5 minutes
        ini_set('max_execution_time', '300');

        // Direct feed: always fetch fresh from relay, no caching
        $key = new Key();
        $excludedPubkeys = [
            $key->convertToHex('npub1etsrcjz24fqewg4zmjze7t5q8c6rcwde5zdtdt4v3t3dz2navecscjjz94'), // Bitcoin Magazine (News Bot)
            $key->convertToHex('npub1m7szwpud3jh2k3cqe73v0fd769uzsj6rzmddh4dw67y92sw22r3sk5m3ys'), // No Bullshit Bitcoin (News Bot)
            $key->convertToHex('npub13wke9s6njrmugzpg6mqtvy2d49g4d6t390ng76dhxxgs9jn3f2jsmq82pk'), // TFTC (News Bot)
            $key->convertToHex('npub10akm29ejpdns52ca082skmc3hr75wmv3ajv4987c9lgyrfynrmdqduqwlx'), // Discreet Log (News Bot)
            $key->convertToHex('npub13uvnw9qehqkds68ds76c4nfcn3y99c2rl9z8tr0p34v7ntzsmmzspwhh99'), // Batcoinz (Just annoying)
            $key->convertToHex('npub1fls5au5fxj6qj0t36sage857cs4tgfpla0ll8prshlhstagejtkqc9s2yl'), // AGORA Marketplace - feed bot
            $key->convertToHex('npub1t5d8kcn0hu8zmt6dpkgatd5hwhx76956g7qmdzwnca6fzgprzlhqnqks86'), // NSFW
            $key->convertToHex('npub14l5xklll5vxzrf6hfkv8m6n2gqevythn5pqc6ezluespah0e8ars4279ss'), // LNgigs
        ];

        // Fetch raw latest articles (limit 50) directly from relay
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

        return $this->render('pages/latest-articles.html.twig', [
            'articles' => $articles,
            'newsBots' => array_slice($excludedPubkeys, 0, 4),
            'authorsMetadata' => $authorsMetadata
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

        return $this->render('magazine/magazine-front.html.twig', [
            'magazine' => $magazine,
            'mag' => $mag,
        ]);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/mag/{mag}/cat/{slug}', name: 'magazine-category')]
    public function magCategory($mag, $slug, EntityManagerInterface $entityManager,
                                RedisCacheService $redisCacheService,
                                FinderInterface $finder,
                                LoggerInterface $logger): Response
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
            // Extract slugs for elasticsearch query
            $slugs = array_map(function($coordinate) {
                $parts = explode(':', $coordinate, 3);
                return end($parts);
            }, $coordinates);
            $slugs = array_filter($slugs); // Remove empty values

            // First filter to only include articles with the slugs we want
            $termsQuery = new Terms('slug', array_values($slugs));

            // Create a Query object to set the size parameter
            $query = new Query($termsQuery);
            $query->setSize(200); // Set size to exceed the number of articles we expect

            $articles = $finder->find($query);

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
                               LoggerInterface $logger): Response
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
            throw $this->createNotFoundException('The article could not be found');
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
        $author = $redisCacheService->getMetadata($article->getPubkey());

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
            'canonical' => $canonical
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
                        <a href="' . $this->generateUrl('article-naddr', ['naddr' => (string)$naddr]) . '" class="alert-link">Click here to view it</a>
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
