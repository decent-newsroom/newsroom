<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\ArticleRepository;
use App\Service\NostrClient;
use App\Util\ForumTopics;
use App\Util\NostrKeyUtil;
use Elastica\Aggregation\Filters as FiltersAgg;
use Elastica\Collapse;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\Term;
use Elastica\Query\Terms;
use FOS\ElasticaBundle\Finder\PaginatedFinderInterface;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ForumController extends AbstractController
{
    #[Route('/forum', name: 'forum')]
    public function index(
        #[Autowire(service: 'fos_elastica.index.articles')] \Elastica\Index $index,
        CacheInterface $cache,
        Request $request,
        NostrClient $nostrClient
    ): Response {
        // Check if Elasticsearch is enabled
        $elasticsearchEnabled = filter_var($this->getParameter('elasticsearch_enabled'), FILTER_VALIDATE_BOOLEAN);

        // Optional: small cache so we don't hammer ES on every page view
        $categoriesWithCounts = $cache->get('forum.index.counts.v2', function (ItemInterface $item) use ($index, $elasticsearchEnabled) {
            $item->expiresAfter(30); // 30s is a nice compromise for "live enough"
            $allTags = $this->flattenAllTags(ForumTopics::TOPICS); // ['tag' => true, ...]

            // Try to fetch counts if Elasticsearch is enabled
            $counts = [];
            if ($elasticsearchEnabled) {
                try {
                    $counts = $this->fetchTagCounts($index, array_keys($allTags)); // ['tag' => count]
                } catch (\Throwable $e) {
                    // Elasticsearch error - return empty counts
                }
            }

            return $this->hydrateCategoryCounts(ForumTopics::TOPICS, $counts);
        });

        $userInterests = null;
        /** @var User $user */
        $user = $this->getUser();
        if (!!$user && $elasticsearchEnabled) {
            try {
                $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                $interests = $nostrClient->getUserInterests($pubkey);
                if (!empty($interests)) {
                    try {
                        $counts = $this->fetchTagCounts($index, array_values($interests)); // ['tag' => count]
                    } catch (\Throwable $e) {
                        // Elasticsearch error - skip user interests
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
        #[Autowire(service: 'fos_elastica.finder.articles')] PaginatedFinderInterface $finder,
        #[Autowire(service: 'fos_elastica.index.articles')] \Elastica\Index $index,
        ArticleRepository $articleRepository,
        Request $request
    ): Response {
        // Check if Elasticsearch is enabled
        $elasticsearchEnabled = filter_var($this->getParameter('elasticsearch_enabled'), FILTER_VALIDATE_BOOLEAN);

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
        if ($elasticsearchEnabled) {
            try {
                $tagCounts = $this->fetchTagCounts($index, $tags);
            } catch (\Throwable $e) {
                // Elasticsearch error - return empty counts
            }
        }

        // Fetch articles for the main topic (OR across all tags), collapse by slug
        $articles = [];
        $pager = null;
        if ($elasticsearchEnabled) {
            try {
                $bool = new BoolQuery();
                if (!empty($tags)) {
                    $bool->addFilter(new Terms('topics', $tags));
                }
                $query = new Query($bool);
                $query->setSize(20);
                $query->setSort(['createdAt' => ['order' => 'desc']]);
                $collapse = new Collapse();
                $collapse->setFieldname('slug');
                $query->setCollapse($collapse);

                /** @var Pagerfanta $pager */
                $pager = $finder->findPaginated($query);
                $pager->setMaxPerPage(20);
                $pager->setCurrentPage(max(1, (int)$request->query->get('page', 1)));
                $articles = iterator_to_array($pager->getCurrentPageResults());
            } catch (\Throwable $e) {
                // Elasticsearch error - return empty articles
            }
        }

        // Latest threads under this main topic scope
        $page    = max(1, (int)$request->query->get('page', 1));
        $perPage = 20;
        $threads = [];
        if ($elasticsearchEnabled) {
            try {
                $threads = $this->fetchThreads($index, [$tags]);
            } catch (\Throwable $e) {
                // Elasticsearch error - return empty threads
            }
        } else {
            // Fallback: fetch from DB directly
            $threads = $this->fetchThreadsFromDb($articleRepository, [$tags]);
        }
        $threadsPage = array_slice($threads, ($page-1)*$perPage, $perPage);

        // Get hydrated topics
        $topics = [];
        if ($elasticsearchEnabled) {
            try {
                $topics = $this->getHydratedTopics($index);
            } catch (\Throwable $e) {
                // Elasticsearch error - return empty topics
                $topics = $this->hydrateCategoryCounts(ForumTopics::TOPICS, []);
            }
        } else {
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
            'articles' => $articles,
            'pager' => $pager,
        ]);
    }

    #[Route('/forum/topic/{key}', name: 'forum_topic')]
    public function topic(
        string $key,
        #[Autowire(service: 'fos_elastica.finder.articles')] PaginatedFinderInterface $finder,
        #[Autowire(service: 'fos_elastica.index.articles')] \Elastica\Index $index,
        NostrClient $nostrClient,
        ArticleRepository $articleRepository,
        Request $request
    ): Response {
        // Check if Elasticsearch is enabled
        $elasticsearchEnabled = filter_var($this->getParameter('elasticsearch_enabled'), FILTER_VALIDATE_BOOLEAN);

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
        if ($elasticsearchEnabled) {
            try {
                $tagCounts = $this->fetchTagCounts($index, $tags);
            } catch (\Throwable $e) {
                // Elasticsearch error - return empty counts
            }
        }

        // Fetch articles for the topic
        $articles = [];
        $pager = null;
        if ($elasticsearchEnabled) {
            try {
                $bool = new BoolQuery();
                $bool->addFilter(new Terms('topics', $tags));

                $query = new Query($bool);
                $query->setSize(20);
                $query->setSort(['createdAt' => ['order' => 'desc']]);
                $collapse = new Collapse();
                $collapse->setFieldname('slug');
                $query->setCollapse($collapse);

                /** @var Pagerfanta $pager */
                $pager = $finder->findPaginated($query);
                $pager->setMaxPerPage(20);
                $pager->setCurrentPage(max(1, (int)$request->query->get('page', 1)));
                $articles = iterator_to_array($pager->getCurrentPageResults());
            } catch (\Throwable $e) {
                // Elasticsearch error - return empty articles
            }
        }

        // Get hydrated topics
        $topics = [];
        if ($elasticsearchEnabled) {
            try {
                $topics = $this->getHydratedTopics($index);
            } catch (\Throwable $e) {
                // Elasticsearch error - return empty topics
                $topics = $this->hydrateCategoryCounts(ForumTopics::TOPICS, []);
            }
        } else {
            $topics = $this->hydrateCategoryCounts(ForumTopics::TOPICS, []);
        }

        return $this->render('forum/topic.html.twig', [
            'categoryKey' => $cat,
            'subcategoryKey' => $sub,
            'topic' => $topic,
            'tags' => $tagCounts,
            'articles' => $articles,
            'pager' => $pager,
            'topics' => $topics,
        ]);
    }

    #[Route('/forum/tag/{tag}', name: 'forum_tag')]
    public function tag(
        string $tag,
        #[Autowire(service: 'fos_elastica.finder.articles')] PaginatedFinderInterface $finder,
        #[Autowire(service: 'fos_elastica.index.articles')] \Elastica\Index $index,
        ArticleRepository $articleRepository,
        Request $request
    ): Response {
        // Check if Elasticsearch is enabled
        $elasticsearchEnabled = filter_var($this->getParameter('elasticsearch_enabled'), FILTER_VALIDATE_BOOLEAN);

        $tag = strtolower(trim($tag));

        $articles = [];
        $pager = null;
        if ($elasticsearchEnabled) {
            try {
                $bool = new BoolQuery();
                // Correct Term usage:
                $bool->addFilter(new Term(['topics' => $tag]));

                $query = new Query($bool);
                $query->setSize(20);

                $query->setSort(['createdAt' => ['order' => 'desc']]);

                /** @var Pagerfanta $pager */
                $pager = $finder->findPaginated($query);
                $pager->setMaxPerPage(20);
                $pager->setCurrentPage(max(1, (int) $request->query->get('page', 1)));
                $articles = iterator_to_array($pager->getCurrentPageResults());
            } catch (\Throwable $e) {
                // Elasticsearch error - return empty articles
            }
        } else {
            // Fallback: fetch from DB directly
            // Use the repository's findByTopics method
            $articlesDb = $articleRepository->findByTopics([$tag], 200);

            // Map to the same format as ES results
            $articles = array_map(static function ($article) {
                return [
                    'id'           => $article->getId(),
                    'slug'         => $article->getSlug(),
                    'title'        => $article->getTitle() ?? '(untitled)',
                    'summary'      => $article->getSummary(),
                    'topics'       => $article->getTopics() ?? [],
                    'createdAt'   => $article->getCreatedAt()?->format('c'),
                ];
            }, $articlesDb);

            // Manual pagination
            $page    = max(1, (int)$request->query->get('page', 1));
            $perPage = 20;
            $total   = count($articles);
            $articles = array_slice($articles, ($page-1)*$perPage, $perPage);

            // Create a simple Pagerfanta instance
            $pager = new Pagerfanta(new \Pagerfanta\Adapter\ArrayAdapter($articlesDb));
            $pager->setMaxPerPage($perPage);
            $pager->setCurrentPage($page);
        }

        // Get hydrated topics
        $topics = [];
        if ($elasticsearchEnabled) {
            try {
                $topics = $this->getHydratedTopics($index);
            } catch (\Throwable $e) {
                // Elasticsearch error - return empty topics
                $topics = $this->hydrateCategoryCounts(ForumTopics::TOPICS, []);
            }
        } else {
            $topics = $this->hydrateCategoryCounts(ForumTopics::TOPICS, []);
        }

        return $this->render('forum/tag.html.twig', [
            'tag' => $tag,
            'articles' => $articles,
            'pager' => $pager, // expose if you want numbered pagination links
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
     * Run one ES query that returns counts for each tag (OR scope per tag).
     * Uses a Filters aggregation keyed by tag to avoid N queries.
     *
     * @param \Elastica\Index $index
     * @param string[] $tags
     * @return array<string,int>
     */
    private function fetchTagCounts(\Elastica\Index $index, array $tags): array
    {
        $tags = array_values(array_unique(array_map('strtolower', array_map('trim', $tags))));
        if (!$tags) return [];

        $q = new Query(new Query\MatchAll());
        $filters = new FiltersAgg('tag_counts');

        foreach ($tags as $tag) {
            $b = new BoolQuery();
            $b->addFilter(new Term(['topics' => $tag])); // topics must be keyword + lowercase normalizer
            $filters->addFilter($b, $tag);
        }

        $q->addAggregation($filters);
        $q->setSize(0);

        $res = $index->search($q);
        $agg = $res->getAggregation('tag_counts')['buckets'] ?? [];

        $out = [];
        foreach ($tags as $tag) {
            $out[$tag] = isset($agg[$tag]['doc_count']) ? (int) $agg[$tag]['doc_count'] : 0;
        }
        return $out;
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
     * (Optional) Fetch latest threads for a given OR-scope of tag groups.
     * You can replace this with your Finder if you want entity hydration.
     *
     * @param array<int,array<int,string>> $tagGroups  e.g. [ ['bitcoin','lightning'] ]
     * @return array<int,array<string,mixed>>
     */
    private function fetchThreads(\Elastica\Index $index, array $tagGroups, int $size = 200): array
    {
        $bool = new BoolQuery();

        // For a simple OR across tags: use Terms query on 'topics'
        // If you pass multiple groups and want AND across groups, adapt here.
        $flatTags = [];
        foreach ($tagGroups as $g) { foreach ($g as $t) { $flatTags[] = strtolower($t); } }
        $flatTags = array_values(array_unique($flatTags));

        if ($flatTags) {
            $bool->addFilter(new Terms('topics', $flatTags));
        }

        $q = (new Query($bool))
            ->setSize($size)
            ->addSort(['createdAt' => ['order' => 'desc']]);

        $rs = $index->search($q);

        // Map raw sources you need (adjust to your mapping)
        return array_map(static function (\Elastica\Result $hit) {
            $s = $hit->getSource();
            return [
                'id'           => $s['id'] ?? $hit->getId(),
                'title'        => $s['title'] ?? '(untitled)',
                'excerpt'      => $s['excerpt'] ?? null,
                'topics'       => $s['topics'] ?? [],
                'created_at'   => $s['createdAt'] ?? null,
            ];
        }, $rs->getResults());
    }

    private function getHydratedTopics(\Elastica\Index $index): array
    {
        $allTags = $this->flattenAllTags(ForumTopics::TOPICS);
        $counts = $this->fetchTagCounts($index, array_keys($allTags));
        return $this->hydrateCategoryCounts(ForumTopics::TOPICS, $counts);
    }

    /**
     * Alternative to fetchThreads that queries the database directly instead of Elasticsearch.
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
