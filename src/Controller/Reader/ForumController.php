<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Entity\Article;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserProfileService;
use App\Service\Nostr\UserRelayListService;
use App\Service\Search\ContentSearchService;
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
    #[Route('/topics', name: 'topics')]
    public function topics(
        ContentSearchService $contentSearch,
        Request $request,
    ): Response {
        // Build flat topics map: key => ['name' => ..., 'tags' => [...]]
        $topics = [];
        foreach (ForumTopics::TOPICS as $catKey => $cat) {
            foreach ($cat['subcategories'] as $subKey => $sub) {
                $key = $catKey . '-' . $subKey;
                $topics[$key] = [
                    'name' => $sub['name'],
                    'tags' => $sub['tags'],
                ];
            }
        }

        $selectedTopic = $request->query->get('topic');
        $articles = [];

        if ($selectedTopic && isset($topics[$selectedTopic])) {
            $articles = $contentSearch->searchByTopics($topics[$selectedTopic]['tags'], limit: 20);
        }

        return $this->render('pages/topics.html.twig', [
            'topics' => $topics,
            'selectedTopic' => $selectedTopic,
            'articles' => $articles,
        ]);
    }

    /**
     * @deprecated Forum index is being replaced by topics integrated into home feeds.
     *             Use the home feed "Interests" or topic search instead.
     */
    #[Route('/forum', name: 'forum')]
    public function index(
        ContentSearchService $contentSearch,
        CacheInterface $cache,
        Request $request,
        NostrClient $nostrClient
    ): Response {
        $categoriesWithCounts = $cache->get('forum.index.counts.v3', function (ItemInterface $item) use ($contentSearch) {
            $item->expiresAfter(30);
            return $contentSearch->buildTaxonomyWithCounts(ForumTopics::TOPICS);
        });

        $userInterests = null;
        /** @var User $user */
        $user = $this->getUser();
        if (!!$user && $contentSearch->isSearchAvailable()) {
            $userInterests = $this->buildUserInterests($user, $nostrClient, $contentSearch);
        }

        return $this->render('forum/index.html.twig', [
            'topics' => $categoriesWithCounts,
            'userInterests' => $userInterests,
            'deprecated' => true,
        ]);
    }

    /**
     * My Interests – shows only the topics matching the user's interest tags (kind 10015),
     * styled like the forum index, with paginated articles.
     */
    #[Route('/my-interests', name: 'my_interests')]
    public function myInterests(
        ContentSearchService $contentSearch,
        NostrClient $nostrClient,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('forum');
        }

        // Fetch interests once — reuse for both the category view and the editor
        $currentInterestTags = [];
        try {
            $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
            $currentInterestTags = $nostrClient->getUserInterests($pubkey);
        } catch (\Exception $e) {
            // Ignore errors
        }

        // Build the filtered interest categories using the already-fetched tags
        $userInterests = $this->buildUserInterests($user, $nostrClient, $contentSearch, $currentInterestTags);

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

        if (!empty($interestTags)) {
            $articles = $contentSearch->searchByTopics($interestTags, limit: $perPage * 10);
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

    /**
     * Interest Set view – renders articles for a specific kind:30015 interest set
     * belonging to the currently logged-in user.
     */
    #[Route('/my-interests/set/{dTag}', name: 'interest_set_view', requirements: ['dTag' => '.+'])]
    public function interestSetView(
        string $dTag,
        ContentSearchService $contentSearch,
        NostrClient $nostrClient,
        Request $request,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('my_interests');
        }

        $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        $sets = $nostrClient->getUserInterestSets($pubkeyHex);
        $set = null;
        foreach ($sets as $s) {
            if ($s['dTag'] === $dTag) {
                $set = $s;
                break;
            }
        }

        if ($set === null) {
            throw $this->createNotFoundException('Interest set not found.');
        }

        $tags = array_values(array_unique(array_map('strtolower', $set['tags'])));
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        $articles = [];

        if (!empty($tags)) {
            $articles = $contentSearch->searchByTopics($tags, limit: $perPage * 10);
        }

        $pager = new Pagerfanta(new ArrayAdapter($articles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);
        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);

        return $this->render('forum/interest_set.html.twig', [
            'set' => $set,
            'articles' => $articlesPage,
            'pager' => $pager,
        ]);
    }

    /**
     * @deprecated Forum main topic pages are being replaced by home feed topic integration.
     */
    #[Route('/forum/main/{topic}', name: 'forum_main_topic')]
    public function mainTopic(
        string $topic,
        ContentSearchService $contentSearch,
        ArticleRepository $articleRepository,
        Request $request
    ): Response {
        $catKey = strtolower(trim($topic));
        if (!isset(ForumTopics::TOPICS[$catKey])) {
            throw $this->createNotFoundException('Main topic not found');
        }

        $category = ForumTopics::TOPICS[$catKey];
        $tags = [];
        foreach ($category['subcategories'] as $sub) {
            foreach ($sub['tags'] as $t) { $tags[] = (string)$t; }
        }
        $tags = array_values(array_unique(array_map('strtolower', array_map('trim', $tags))));

        $tagCounts = $contentSearch->getTopicsMetadata($tags);

        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = 20;
        $articles = $contentSearch->searchByTopics($tags, limit: $perPage * 10);

        $total = count($articles);
        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);

        $pager = new Pagerfanta(new ArrayAdapter($articles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);

        // Latest threads under this main topic scope
        $threads = $this->fetchThreadsFromDb($articleRepository, [$tags]);
        $threadsPage = array_slice($threads, ($page - 1) * $perPage, $perPage);

        $topics = $contentSearch->buildTaxonomyWithCounts(ForumTopics::TOPICS);

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
            'deprecated' => true,
        ]);
    }

    /**
     * @deprecated Forum topic pages are being replaced by home feed topic integration.
     */
    #[Route('/forum/topic/{key}', name: 'forum_topic')]
    public function topic(
        string $key,
        ContentSearchService $contentSearch,
        NostrClient $nostrClient,
        ArticleRepository $articleRepository,
        EventRepository $eventRepository,
        Request $request
    ): Response {
        // key format: "{category}-{subcategory}"
        $key = trim($key);
        [$cat, $sub] = array_pad(explode('-', $key, 2), 2, null);
        $cat = strtolower($cat);
        // Only lowercase sub for standard forum topics (interest set d-tags are case-sensitive)
        if ($cat !== 'isets') {
            $sub = $sub !== null ? strtolower($sub) : null;
        }

        if ($cat === 'interests' && $sub === 'all') {
            $allTags = [];
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
        } else if ($cat === 'isets' && $sub !== null) {
            $coordParts = explode(':', $sub, 2);
            if (count($coordParts) !== 2) {
                throw $this->createNotFoundException('Invalid interest set coordinate');
            }
            [$setPubkey, $setDTag] = $coordParts;
            $setEvent = $eventRepository->findByNaddr(KindsEnum::INTEREST_SETS->value, $setPubkey, $setDTag);
            if (!$setEvent) {
                throw $this->createNotFoundException('Interest set not found');
            }
            $setTags = [];
            foreach ($setEvent->getTags() as $tag) {
                if (is_array($tag) && ($tag[0] ?? '') === 't' && isset($tag[1])) {
                    $setTags[] = strtolower(trim((string) $tag[1]));
                }
            }
            $topic = [
                'name' => $setEvent->getTitle() ?? $setDTag,
                'tags' => $setTags,
            ];
        } else if (!$cat || !$sub || !isset(ForumTopics::TOPICS[$cat]['subcategories'][$sub])) {
            throw $this->createNotFoundException('Topic not found');
        } else {
            $topic = ForumTopics::TOPICS[$cat]['subcategories'][$sub];
        }

        $tags = array_map('strval', $topic['tags']);
        $tagCounts = $contentSearch->getTopicsMetadata($tags);

        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = 20;
        $articles = $contentSearch->searchByTopics($tags, limit: $perPage * 10);

        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);

        $pager = new Pagerfanta(new ArrayAdapter($articles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);

        $topics = $contentSearch->buildTaxonomyWithCounts(ForumTopics::TOPICS);

        return $this->render('forum/topic.html.twig', [
            'categoryKey' => $cat,
            'subcategoryKey' => $sub,
            'topic' => $topic,
            'tags' => $tagCounts,
            'articles' => $articlesPage,
            'pager' => $pager,
            'topics' => $topics,
            'deprecated' => true,
        ]);
    }

    /**
     * @deprecated Forum tag pages are being replaced by home feed topic integration.
     */
    #[Route('/forum/tag/{tag}', name: 'forum_tag')]
    public function tag(
        string $tag,
        ContentSearchService $contentSearch,
        Request $request
    ): Response {
        $tag = strtolower(trim($tag));

        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = 20;
        $articles = $contentSearch->searchByTopics([$tag], limit: $perPage * 10);

        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);

        $pager = new Pagerfanta(new ArrayAdapter($articles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);

        $topics = $contentSearch->buildTaxonomyWithCounts(ForumTopics::TOPICS);

        return $this->render('forum/tag.html.twig', [
            'tag' => $tag,
            'articles' => $articlesPage,
            'pager' => $pager,
            'topics' => $topics,
            'deprecated' => true,
        ]);
    }

    // ---------- Helpers ----------

    /**
     * Return the raw tags array from the user's current kind 10015 interests event.
     * Used by the interest-set Follow action to merge a new "a" tag before re-signing.
     */
    #[Route('/api/interests/current-tags', name: 'api_interests_current_tags', methods: ['GET'])]
    public function currentInterestTags(
        EventRepository $eventRepository,
    ): JsonResponse {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'Not authenticated'], 401);
        }

        try {
            $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
            $event = $eventRepository->findLatestByPubkeyAndKind($pubkey, KindsEnum::INTERESTS->value);

            return new JsonResponse([
                'tags' => $event?->getTags() ?? [],
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['tags' => []]);
        }
    }

    /**
     * Publish a kind 10015 interests event.
     * Receives a signed event from the frontend, validates it, and broadcasts to relays.
     */
    #[Route('/api/interests/publish', name: 'api_interests_publish', methods: ['POST'])]
    public function publishInterests(
        Request $request,
        NostrClient $nostrClient,
        UserRelayListService $userRelayListService,
        UserProfileService $userProfileService,
        LoggerInterface $logger,
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['event'])) {
                return new JsonResponse(['error' => 'Invalid request data'], 400);
            }

            $signedEvent = $data['event'];

            if (!isset($signedEvent['id'], $signedEvent['pubkey'], $signedEvent['created_at'],
                       $signedEvent['kind'], $signedEvent['tags'], $signedEvent['sig'])) {
                return new JsonResponse(['error' => 'Missing required event fields'], 400);
            }

            if ((int) $signedEvent['kind'] !== KindsEnum::INTERESTS->value) {
                return new JsonResponse(['error' => 'Invalid event kind, expected ' . KindsEnum::INTERESTS->value], 400);
            }

            $eventObj = new Event();
            $eventObj->setId($signedEvent['id']);
            $eventObj->setPublicKey($signedEvent['pubkey']);
            $eventObj->setCreatedAt($signedEvent['created_at']);
            $eventObj->setKind($signedEvent['kind']);
            $eventObj->setTags($signedEvent['tags']);
            $eventObj->setContent($signedEvent['content'] ?? '');
            $eventObj->setSignature($signedEvent['sig']);

            if (!$eventObj->verify()) {
                return new JsonResponse(['error' => 'Event signature verification failed'], 400);
            }

            $userProfileService->persistInterestEvent((object) $signedEvent);

            $pubkey = $signedEvent['pubkey'];
            $relays = $userRelayListService->getRelaysForPublishing($pubkey);

            $logger->info('Publishing interests event', [
                'event_id' => $signedEvent['id'],
                'pubkey' => $pubkey,
                'tag_count' => count(array_filter($signedEvent['tags'], fn($t) => $t[0] === 't')),
                'relay_count' => count($relays),
            ]);

            $relayResults = $nostrClient->publishEvent($eventObj, $relays);

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
     * Fetch latest threads for a given OR-scope of tag groups from the database.
     *
     * @param ArticleRepository $repository
     * @param array<int,array<int,string>> $tagGroups
     * @param int $size
     * @return array<int,array<string,mixed>>
     */
    private function fetchThreadsFromDb(ArticleRepository $repository, array $tagGroups, int $size = 200): array
    {
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

        $articles = $repository->findByTopics($flatTags, $size);

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
     */
    private function buildUserInterests(User $user, NostrClient $nostrClient, ContentSearchService $contentSearch, ?array $prefetchedInterests = null): ?array
    {
        try {
            $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
            $interests = $prefetchedInterests ?? $nostrClient->getUserInterests($pubkey);

            $counts = [];
            if (!empty($interests)) {
                $counts = $contentSearch->getTopicsMetadata(array_values($interests));
            }

            $userInterests = [];

            try {
                $interestSets = $nostrClient->getUserInterestSets($pubkey);
            } catch (\Throwable $e) {
                $interestSets = [];
            }

            if (!empty($interestSets)) {
                $allSetTags = [];
                foreach ($interestSets as $set) {
                    foreach ($set['tags'] as $tag) {
                        $allSetTags[strtolower($tag)] = true;
                    }
                }
                $setCounts = $contentSearch->getTopicsMetadata(array_keys($allSetTags));
                $counts = array_merge($counts, $setCounts);

                $userInterests['isets'] = [
                    'name' => 'Interest Sets',
                    'subcategories' => [],
                ];

                foreach ($interestSets as $set) {
                    $subKey = $set['pubkey'] . ':' . $set['dTag'];
                    $sum = 0;
                    foreach ($set['tags'] as $tag) {
                        $sum += $setCounts[strtolower($tag)] ?? 0;
                    }
                    $userInterests['isets']['subcategories'][$subKey] = [
                        'name'     => $set['title'],
                        'tags'     => $set['tags'],
                        'count'    => $sum,
                        'followed' => $set['followed'] ?? false,
                        'owned'    => $set['owned'] ?? false,
                    ];
                }
            }

            if (!empty($interests)) {
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

            return !empty($userInterests) ? $userInterests : null;
        } catch (\Exception $e) {
            // Ignore errors
        }

        return null;
    }
}
