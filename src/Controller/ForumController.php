<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ArticleRepository;
use App\Service\NostrClient;
use App\Service\Search\ArticleSearchInterface;
use App\Util\ForumTopics;
use App\Util\NostrKeyUtil;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
                }
            } catch (\Exception $e) {
                // Ignore errors, just don't show user interests
            }
        }

        return $this->render('forum/index.html.twig', [
            'topics' => $categoriesWithCounts,
            'userInterests' => $userInterests,
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
}
