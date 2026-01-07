<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\FetchAuthorArticlesMessage;
use App\Service\RedisCacheService;
use App\Service\RedisViewStore;
use App\Service\Search\ArticleSearchInterface;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use swentel\nostr\Nip19\Nip19Helper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

class AuthorController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Reading List Index
     */
    #[Route('/p/{npub}/lists', name: 'author-reading-lists')]
    public function readingLists($npub,
                                EntityManagerInterface $em,
                                NostrKeyUtil $keyUtil,
                                LoggerInterface $logger): Response
    {
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
    #[Route('/p/{npub}/list/{slug}', name: 'reading-list')]
    public function readingList($npub, $slug,
                                EntityManagerInterface $em,
                                NostrKeyUtil $keyUtil,
                                LoggerInterface $logger): Response
    {
        // Convert npub to hex pubkey
        $pubkey = $keyUtil->npubToHex($npub);
        $logger->info(sprintf('Reading list: pubkey=%s, slug=%s', $pubkey, $slug));

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

            // Query database directly for each coordinate
            foreach ($coordinates as $coord) {
                $parts = explode(':', $coord, 3);
                if (count($parts) === 3) {
                    [$kind, $author, $articleSlug] = $parts;

                    // Find the most recent event matching this coordinate
                    $events = $articleRepo->findBy([
                        'slug' => $articleSlug,
                        'pubkey' => $author
                    ], ['createdAt' => 'DESC']);

                    // Filter by slug and get the latest
                    foreach ($events as $event) {
                        if ($event->getSlug() === $articleSlug) {
                            $articles[] = $event;
                            break; // Take the first match (most recent if ordered)
                        }
                    }
                }
            }
        }

        return $this->render('pages/list.html.twig', [
            'list' => $list,
            'articles' => $articles,
        ]);
    }

    /**
     * Multimedia
     * @throws Exception|InvalidArgumentException
     */
    #[Route('/p/{npub}/media', name: 'author-media', requirements: ['npub' => '^npub1.*'])]
    public function media($npub, RedisCacheService $redisCacheService, NostrKeyUtil $keyUtil): Response
    {
        $pubkey = $keyUtil->npubToHex($npub);
        $author = $redisCacheService->getMetadata($pubkey);

        // Use paginated cached media events - fetches 200 from relays, serves first 24
        $paginatedData = $redisCacheService->getMediaEventsPaginated($pubkey, 1, 24);
        $mediaEvents = $paginatedData['events'];

        // Encode event IDs as note1... for each event
        foreach ($mediaEvents as $event) {
            $nip19 = new Nip19Helper();
            $event->noteId = $nip19->encodeNote($event->id);
        }

        return $this->render('profile/author-media.html.twig', [
            'author' => $author,
            'npub' => $npub,
            'pubkey' => $pubkey,
            'pictureEvents' => $mediaEvents,
            'hasMore' => $paginatedData['hasMore'],
            'total' => $paginatedData['total'],
            'is_author_profile' => true,
        ]);
    }

    /**
     * AJAX endpoint to load more media events
     * @throws Exception
     */
    #[Route('/p/{npub}/media/load-more', name: 'author-media-load-more', requirements: ['npub' => '^npub1.*'])]
    public function mediaLoadMore($npub, Request $request, RedisCacheService $redisCacheService): Response
    {
        $page = $request->query->getInt('page', 2); // Default to page 2

        // Get paginated data from cache - 24 items per page
        $paginatedData = $redisCacheService->getMediaEventsPaginated($npub, $page, 24);
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
     * Author profile and articles
     * @throws Exception
     * @throws ExceptionInterface
     * @throws InvalidArgumentException
     */
    #[Route('/p/{npub}', name: 'author-profile', requirements: ['npub' => '^npub1.*'])]
    #[Route('/p/{npub}/articles', name: 'author-articles', requirements: ['npub' => '^npub1.*'])]
    public function index($npub, RedisCacheService $redisCacheService, FinderInterface $finder,
                          MessageBusInterface $messageBus, RedisViewStore $viewStore,
                          RedisViewFactory $viewFactory, ArticleSearchInterface $articleSearch): Response
    {
        $keys = new Key();
        $pubkey = $keys->convertToHex($npub);

        $author = $redisCacheService->getMetadata($pubkey);

        // Check if viewer is the author
        $currentUser = $this->getUser();
        $isOwnProfile = $currentUser && $currentUser->getUserIdentifier() === $npub;

        // Query fresh Article entities (not cached view data)
        // This ensures we have proper entities for filtering logic
        $articles = $articleSearch->findByPubkey($pubkey, 100, 0);

        // Filter and deduplicate articles at the entity level
        $articles = $this->filterAndDeduplicateArticles($articles, $isOwnProfile);

        // Build view objects for template from filtered entities
        $viewData = [];
        if (!empty($articles)) {
            try {
                foreach ($articles as $article) {
                    if ($article instanceof Article) {
                        $baseObject = $viewFactory->articleBaseObject($article, $author);
                        $normalized = $viewFactory->normalizeBaseObject($baseObject);
                        // Extract just the article data and convert to object
                        // This matches what the template expects (same format as old cache code)
                        if (isset($normalized['article'])) {
                            $viewData[] = (object) $normalized['article'];
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to build view objects', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        $fromCache = false;

        // Get latest createdAt for dispatching fetch message
        if (!empty($articles)) {
            // Articles are now guaranteed to be Article entities
            $latest = $articles[0]->getCreatedAt()->getTimestamp();
            // Dispatch async message to fetch new articles since latest + 1
            $messageBus->dispatch(new FetchAuthorArticlesMessage($pubkey, $latest + 1));
        } else {
            // No articles, fetch all
            $messageBus->dispatch(new FetchAuthorArticlesMessage($pubkey, 0));
        }

        return $this->render('profile/author.html.twig', [
            'author' => $author,
            'npub' => $npub,
            'pubkey' => $pubkey,
            'articles' => $viewData, // Pass normalized view data to template
            'is_author_profile' => true,
            'from_cache' => $fromCache,
        ]);
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
            if (!$isOwnProfile && $kind === KindsEnum::LONGFORM_DRAFT->value) {
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
     * Redirect from /p/{pubkey} to /p/{npub}
     * @throws Exception
     */
    #[Route('/p/{pubkey}', name: 'author-redirect')]
    public function authorRedirect($pubkey): Response
    {
        $keys = new Key();
        $npub = $keys->convertPublicKeyToBech32($pubkey);
        return $this->redirectToRoute('author-profile', ['npub' => $npub]);
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
}
