<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Entity\Article;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use App\Service\Search\ArticleSearchInterface;
use App\Util\ForumTopics;
use App\Util\NostrKeyUtil;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ForumController extends AbstractController
{
    #[Route('/forum', name: 'forum')]
    public function index(
        ArticleSearchInterface $articleSearch,
        CacheInterface $cache,
        Request $request,
        NostrClient $nostrClient
    ): Response {
        // Optional: small cache so we don't hammer the search service on every page view
        $categoriesWithCounts = $cache->get('forum.index.counts.v2', function (ItemInterface $item) use ($articleSearch) {
            $item->expiresAfter(30); // 30s is a nice compromise for "live enough"
            $allTags = $this->flattenAllTags(ForumTopics::TOPICS); // ['tag' => true, ...]

            // Fetch counts via the search service
            $counts = [];
            if ($articleSearch->isAvailable()) {
                try {
                    $counts = $articleSearch->getTagCounts(array_keys($allTags)); // ['tag' => count]
                } catch (\Throwable $e) {
                    // Search error - return empty counts
                }
            }

            return $this->hydrateCategoryCounts(ForumTopics::TOPICS, $counts);
        });

        $userInterests = null;
        /** @var User $user */
        $user = $this->getUser();
        if (!!$user && $articleSearch->isAvailable()) {
            $userInterests = $this->buildUserInterests($user, $nostrClient, $articleSearch);
        }

        return $this->render('forum/index.html.twig', [
            'topics' => $categoriesWithCounts,
            'userInterests' => $userInterests,
        ]);
    }

    /**
     * My Interests – shows only the topics matching the user's interest tags (kind 10015),
     * styled like the forum index, with paginated articles.
     */
    #[Route('/my-interests', name: 'my_interests')]
    public function myInterests(
        ArticleSearchInterface $articleSearch,
        NostrClient $nostrClient,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('forum');
        }

        // Build the filtered interest categories
        $userInterests = $this->buildUserInterests($user, $nostrClient, $articleSearch);

        // Get raw interest tags for the editor
        $currentInterestTags = [];
        try {
            $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
            $currentInterestTags = $nostrClient->getUserInterests($pubkey);
        } catch (\Exception $e) {
            // Ignore errors
        }

        // Build popular tags grouped by category for the editor
        $popularTags = ForumTopics::allUniqueTags();
        $groupedTags = ForumTopics::groupedTags();

        // Collect all interest tags for the article listing
        $interestTags = [];
        if ($userInterests) {
            foreach ($userInterests as $cat) {
                foreach ($cat['subcategories'] as $sub) {
                    foreach ($sub['tags'] as $tag) {
                        $interestTags[] = strtolower($tag);
                    }
                }
            }
            $interestTags = array_values(array_unique($interestTags));
        }

        // Fetch articles matching the interest tags
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        $articles = [];

        if (!empty($interestTags) && $articleSearch->isAvailable()) {
            try {
                $articles = $articleSearch->findByTopics($interestTags, $perPage * 10, 0);
                $articles = $this->deduplicateArticles($articles);
            } catch (\Throwable $e) {
                // Search error - return empty articles
            }
        }

        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);

        $pager = new Pagerfanta(new ArrayAdapter($articles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);

        return $this->render('forum/my_interests.html.twig', [
            'userInterests' => $userInterests,
            'articles' => $articlesPage,
            'pager' => $pager,
            'popularTags' => $popularTags,
            'groupedTags' => $groupedTags,
            'currentInterestTags' => $currentInterestTags,
        ]);
    }

    #[Route('/forum/main/{topic}', name: 'forum_main_topic')]
    public function mainTopic(
        string $topic,
        ArticleSearchInterface $articleSearch,
        ArticleRepository $articleRepository,
        Request $request
    ): Response {
        $catKey = strtolower(trim($topic));
        if (!isset(ForumTopics::TOPICS[$catKey])) {
            throw $this->createNotFoundException('Main topic not found');
        }

        $category = ForumTopics::TOPICS[$catKey];
        // Collect all tags from all subcategories under this main topic
        $tags = [];
        foreach ($category['subcategories'] as $sub) {
            foreach ($sub['tags'] as $t) { $tags[] = (string)$t; }
        }
        $tags = array_values(array_unique(array_map('strtolower', array_map('trim', $tags))));

        // Count each tag in this main topic in one shot
        $tagCounts = [];
        try {
            $tagCounts = $articleSearch->getTagCounts($tags);
        } catch (\Throwable $e) {
            // Search error - return empty counts
        }

        // Fetch articles for the main topic (OR across all tags)
        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = 20;
        $articles = [];

        try {
            $articles = $articleSearch->findByTopics($tags, $perPage * 10, 0); // Fetch more for pagination
            $articles = $this->deduplicateArticles($articles); // Remove duplicates by npub+slug
        } catch (\Throwable $e) {
            // Search error - return empty articles
        }

        // Manual pagination
        $total = count($articles);
        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);

        // Create a pagerfanta instance
        $pager = new Pagerfanta(new ArrayAdapter($articles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);

        // Latest threads under this main topic scope
        $threads = $this->fetchThreadsFromDb($articleRepository, [$tags]);
        $threadsPage = array_slice($threads, ($page - 1) * $perPage, $perPage);

        // Get hydrated topics
        $topics = [];
        try {
            $allTags = $this->flattenAllTags(ForumTopics::TOPICS);
            $counts = $articleSearch->getTagCounts(array_keys($allTags));
            $topics = $this->hydrateCategoryCounts(ForumTopics::TOPICS, $counts);
        } catch (\Throwable $e) {
            $topics = $this->hydrateCategoryCounts(ForumTopics::TOPICS, []);
        }

        return $this->render('forum/main_topic.html.twig', [
            'categoryKey' => $catKey,
            'category' => [ 'name' => $category['name'] ?? ucfirst($catKey) ],
            'tags' => $tagCounts,
            'threads' => $threadsPage,
            'total' => count($threads),
            'page' => $page,
            'perPage' => $perPage,
            'topics' => $topics,
            'articles' => $articlesPage,
            'pager' => $pager,
        ]);
    }

    #[Route('/forum/topic/{key}', name: 'forum_topic')]
    public function topic(
        string $key,
        ArticleSearchInterface $articleSearch,
        NostrClient $nostrClient,
        ArticleRepository $articleRepository,
        Request $request
    ): Response {
        // key format: "{category}-{subcategory}"
        $key = strtolower(trim($key));
        [$cat, $sub] = array_pad(explode('-', $key, 2), 2, null);

        if ($cat === 'interests' && $sub === 'all') {
            // Special case for "All Interests" pseudo-topic
            $allTags = []; // will be filled below
            /** @var User $user */
            $user = $this->getUser();
            if (!!$user) {
                try {
                    $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                    $interests = $nostrClient->getUserInterests($pubkey);
                    if (!empty($interests)) {
                        $allTags = array_map('strtolower', array_values($interests));
                    }
                } catch (\Exception $e) {
                    // Ignore errors, just show empty topic
                }
            }
            $topic = [
                'name' => 'All Interests',
                'tags' => $allTags,
            ];
        } else if (!$cat || !$sub || !isset(ForumTopics::TOPICS[$cat]['subcategories'][$sub])) {
            throw $this->createNotFoundException('Topic not found');
        } else {
            $topic = ForumTopics::TOPICS[$cat]['subcategories'][$sub];
        }

        // Count each tag in this subcategory in one shot
        $tags = array_map('strval', $topic['tags']);
        $tagCounts = [];
        try {
            $tagCounts = $articleSearch->getTagCounts($tags);
        } catch (\Throwable $e) {
            // Search error - return empty counts
        }

        // Fetch articles for the topic
        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = 20;
        $articles = [];

        try {
            $articles = $articleSearch->findByTopics($tags, $perPage * 10, 0); // Fetch more for pagination
            $articles = $this->deduplicateArticles($articles); // Remove duplicates by npub+slug
        } catch (\Throwable $e) {
            // Search error - return empty articles
        }

        // Manual pagination
        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);

        // Create a pagerfanta instance
        $pager = new Pagerfanta(new ArrayAdapter($articles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);

        // Get hydrated topics
        $topics = [];
        try {
            $allTags = $this->flattenAllTags(ForumTopics::TOPICS);
            $counts = $articleSearch->getTagCounts(array_keys($allTags));
            $topics = $this->hydrateCategoryCounts(ForumTopics::TOPICS, $counts);
        } catch (\Throwable $e) {
            $topics = $this->hydrateCategoryCounts(ForumTopics::TOPICS, []);
        }

        return $this->render('forum/topic.html.twig', [
            'categoryKey' => $cat,
            'subcategoryKey' => $sub,
            'topic' => $topic,
            'tags' => $tagCounts,
            'articles' => $articlesPage,
            'pager' => $pager,
            'topics' => $topics,
        ]);
    }

    #[Route('/forum/tag/{tag}', name: 'forum_tag')]
    public function tag(
        string $tag,
        ArticleSearchInterface $articleSearch,
        Request $request
    ): Response {
        $tag = strtolower(trim($tag));

        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = 20;
        $articles = [];

        try {
            $articles = $articleSearch->findByTag($tag, $perPage * 10, 0); // Fetch more for pagination
            $articles = $this->deduplicateArticles($articles); // Remove duplicates by npub+slug
        } catch (\Throwable $e) {
            // Search error - return empty articles
        }

        // Manual pagination
        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);

        // Create a pagerfanta instance
        $pager = new Pagerfanta(new ArrayAdapter($articles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);

        // Get hydrated topics
        $topics = [];
        try {
            $allTags = $this->flattenAllTags(ForumTopics::TOPICS);
            $counts = $articleSearch->getTagCounts(array_keys($allTags));
            $topics = $this->hydrateCategoryCounts(ForumTopics::TOPICS, $counts);
        } catch (\Throwable $e) {
            $topics = $this->hydrateCategoryCounts(ForumTopics::TOPICS, []);
        }

        return $this->render('forum/tag.html.twig', [
            'tag' => $tag,
            'articles' => $articlesPage,
            'pager' => $pager,
            'topics' => $topics,
        ]);
    }

    // ---------- Helpers ----------

    /**
     * Publish a kind 10015 interests event.
     * Receives a signed event from the frontend, validates it, and broadcasts to relays.
     */
    #[Route('/api/interests/publish', name: 'api_interests_publish', methods: ['POST'])]
    public function publishInterests(
        Request $request,
        NostrClient $nostrClient,
        UserRelayListService $userRelayListService,
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
                       $signedEvent['kind'], $signedEvent['tags'], $signedEvent['sig'])) {
                return new JsonResponse(['error' => 'Missing required event fields'], 400);
            }

            // Validate kind
            if ((int) $signedEvent['kind'] !== KindsEnum::INTERESTS->value) {
                return new JsonResponse(['error' => 'Invalid event kind, expected ' . KindsEnum::INTERESTS->value], 400);
            }

            // Convert to Event object
            $eventObj = new Event();
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

            // Collect relays for publishing
            $pubkey = $signedEvent['pubkey'];
            $relays = $userRelayListService->getRelaysForPublishing($pubkey);

            $logger->info('Publishing interests event', [
                'event_id' => $signedEvent['id'],
                'pubkey' => $pubkey,
                'tag_count' => count(array_filter($signedEvent['tags'], fn($t) => $t[0] === 't')),
                'relay_count' => count($relays),
            ]);

            // Publish to relays (empty array lets NostrClient fetch author's relays)
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

            $logger->info('Interests event published', [
                'event_id' => $signedEvent['id'],
                'success_count' => $successCount,
                'fail_count' => $failCount,
            ]);

            return new JsonResponse([
                'status' => 'ok',
                'event_id' => $signedEvent['id'],
                'relayResults' => $relayStatuses,
            ]);

        } catch (\Exception $e) {
            $logger->error('Error publishing interests event', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'error' => 'Failed to publish interests: ' . $e->getMessage()
            ], 500);
        }
    }

    // ---------- Private helpers ----------

    /**
     * Flatten all tags from the taxonomy into a unique set.
     * @return array<string, true>
     */
    private function flattenAllTags(array $categories): array
    {
        $set = [];
        foreach ($categories as $cat) {
            foreach ($cat['subcategories'] as $sub) {
                foreach ($sub['tags'] as $tag) {
                    $set[strtolower($tag)] = true;
                }
            }
        }
        return $set;
    }

    /**
     * Rehydrate taxonomy with counts per subcategory (sum of its tags).
     * @param array<string,int> $counts
     */
    private function hydrateCategoryCounts(array $taxonomy, array $counts): array
    {
        $out = [];
        foreach ($taxonomy as $catKey => $cat) {
            $subs = [];
            foreach ($cat['subcategories'] as $subKey => $sub) {
                $sum = 0;
                foreach ($sub['tags'] as $tag) {
                    $sum += $counts[strtolower($tag)] ?? 0;
                }
                $subs[$subKey] = $sub + ['count' => $sum];
            }
            $out[$catKey] = $cat;
            $out[$catKey]['subcategories'] = $subs;
        }
        return $out;
    }

    /**
     * Deduplicate articles by pubkey+slug combination.
     * Keeps the first occurrence of each unique pubkey+slug pair.
     *
     * @param Article[] $articles
     * @return Article[]
     */
    private function deduplicateArticles(array $articles): array
    {
        $seen = [];
        $unique = [];

        foreach ($articles as $article) {
            $pubkey = $article->getPubkey();
            $slug = $article->getSlug();
            $key = $pubkey . '|' . $slug;

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $article;
            }
        }

        return $unique;
    }

    /**
     * Fetch latest threads for a given OR-scope of tag groups from the database.
     *
     * @param ArticleRepository $repository
     * @param array<int,array<int,string>> $tagGroups  e.g. [ ['bitcoin','lightning'] ]
     * @param int $size
     * @return array<int,array<string,mixed>>
     */
    private function fetchThreadsFromDb(ArticleRepository $repository, array $tagGroups, int $size = 200): array
    {
        // Flatten all tags from groups
        $flatTags = [];
        foreach ($tagGroups as $g) {
            foreach ($g as $t) {
                $flatTags[] = strtolower($t);
            }
        }
        $flatTags = array_values(array_unique($flatTags));

        if (empty($flatTags)) {
            return [];
        }

        // Use the repository's findByTopics method
        $articles = $repository->findByTopics($flatTags, $size);

        // Map to the same format as fetchThreads
        return array_map(static function ($article) {
            return [
                'id'           => $article->getId(),
                'title'        => $article->getTitle() ?? '(untitled)',
                'excerpt'      => $article->getSummary(),
                'topics'       => $article->getTopics() ?? [],
                'created_at'   => $article->getCreatedAt()?->format('c'),
            ];
        }, $articles);
    }

    /**
     * Build the user interests array for the given user.
     * Extracted as a reusable helper for the index and myInterests actions.
     */
    private function buildUserInterests(User $user, NostrClient $nostrClient, ArticleSearchInterface $articleSearch)
    {
        try {
            $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
            $interests = $nostrClient->getUserInterests($pubkey);
            if (!empty($interests)) {
                try {
                    $counts = $articleSearch->getTagCounts(array_values($interests)); // ['tag' => count]
                } catch (\Throwable $e) {
                    // Search error - skip user interests
                    $counts = [];
                    $interests = [];
                }
                $userInterests = $this->hydrateCategoryCounts(ForumTopics::TOPICS, $counts);
                // Filter to only include subcategories that have tags in interests
                foreach ($userInterests as $catKey => $cat) {
                    $subs = [];
                    foreach ($cat['subcategories'] as $subKey => $sub) {
                        $subTags = array_map('strtolower', $sub['tags']);
                        if (array_intersect($subTags, $interests)) {
                            $subs[$subKey] = $sub;
                        }
                    }
                    if (!empty($subs)) {
                        $userInterests[$catKey]['subcategories'] = $subs;
                    } else {
                        unset($userInterests[$catKey]);
                    }
                }
                // All user interest combined
                $userInterests['interests'] = [
                    'name' => 'Interests',
                    'subcategories' => [],
                ];
                $userInterests['interests']['subcategories']['all'] = [
                    'name' => 'All Interests',
                    'tags' => [],
                    'count' => 0,
                ];
                foreach ($interests as $tag) {
                    $userInterests['interests']['subcategories']['all']['tags'][] = $tag;
                    $userInterests['interests']['subcategories']['all']['count'] += $counts[strtolower($tag)] ?? 0;
                }

                return $userInterests;
            }
        } catch (\Exception $e) {
            // Ignore errors, just don't show user interests
        }

        return null;
    }
}
