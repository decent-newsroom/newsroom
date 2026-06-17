<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Entity\User;
use App\Enum\KindsEnum;
use App\Helper\NavigationBuilderTrait;
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
    use NavigationBuilderTrait;

    #[Route('/topics', name: 'topics')]
    public function topics(ContentSearchService $contentSearch, Request $request): Response
    {
        $topics = [];
        foreach (ForumTopics::TOPICS as $catKey => $cat) {
            foreach (($cat['subcategories'] ?? []) as $subKey => $sub) {
                $topics[$catKey . '-' . $subKey] = [
                    'name' => $sub['name'] ?? $subKey,
                    'tags' => $sub['tags'] ?? [],
                ];
            }
        }

        $selectedTopic = (string) $request->query->get('topic', '');
        $articles = [];
        if ($selectedTopic !== '' && isset($topics[$selectedTopic])) {
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
     */
    #[Route('/forum', name: 'forum')]
    public function index(
        ContentSearchService $contentSearch,
        CacheInterface $cache,
        Request $request,
        NostrClient $nostrClient,
    ): Response {
        $categoriesWithCounts = $cache->get('forum.index.counts.v3', function (ItemInterface $item) use ($contentSearch) {
            $item->expiresAfter(30);
            return $contentSearch->buildTaxonomyWithCounts(ForumTopics::TOPICS);
        });

        $userInterests = null;
        /** @var User|null $user */
        $user = $this->getUser();
        if ($user && $contentSearch->isSearchAvailable()) {
            $userInterests = $this->buildUserInterests($user, $nostrClient, $contentSearch);
        }

        return $this->render('forum/index.html.twig', [
            'topics' => $categoriesWithCounts,
            'userInterests' => $userInterests,
            'deprecated' => true,
        ]);
    }

    /**
     * My Interests – shows only the topics matching the user's interest tags (kind 10015).
     */
    #[Route('/my-interests', name: 'my_interests')]
    public function myInterests(
        ContentSearchService $contentSearch,
        NostrClient $nostrClient,
        Request $request,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('forum');
        }

        $currentInterestTags = [];
        try {
            $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
            $currentInterestTags = $nostrClient->getUserInterests($pubkey);
        } catch (\Throwable) {
        }

        $userInterests = $this->buildUserInterests($user, $nostrClient, $contentSearch, $currentInterestTags);
        $popularTags = ForumTopics::allUniqueTags();
        $groupedTags = ForumTopics::groupedTags();

        $interestTags = [];
        if ($userInterests) {
            foreach ($userInterests as $cat) {
                foreach (($cat['subcategories'] ?? []) as $sub) {
                    foreach (($sub['tags'] ?? []) as $tag) {
                        $interestTags[] = strtolower((string) $tag);
                    }
                }
            }
            $interestTags = array_values(array_unique($interestTags));
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        $articles = !empty($interestTags)
            ? $contentSearch->searchByTopics($interestTags, limit: $perPage * 10)
            : [];

        $pager = new Pagerfanta(new ArrayAdapter($articles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);

        return $this->render('forum/my_interests.html.twig', [
            'readingNookNav' => $this->buildReadingNookNav(),
            'userInterests' => $userInterests,
            'articles' => array_slice($articles, ($page - 1) * $perPage, $perPage),
            'pager' => $pager,
            'popularTags' => $popularTags,
            'groupedTags' => $groupedTags,
            'currentInterestTags' => $currentInterestTags,
        ]);
    }

    /**
     * Interest Set view – renders articles for a specific kind:30015 interest set belonging to the currently logged-in user.
     */
    #[Route('/my-interests/set/{dTag}', name: 'interest_set_view', requirements: ['dTag' => '.+'])]
    public function interestSetView(
        string $dTag,
        ContentSearchService $contentSearch,
        NostrClient $nostrClient,
        Request $request,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('my_interests');
        }

        $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        $sets = $nostrClient->getUserInterestSets($pubkeyHex);
        $set = null;
        foreach ($sets as $s) {
            if (($s['dTag'] ?? null) === $dTag) {
                $set = $s;
                break;
            }
        }

        if ($set === null) {
            throw $this->createNotFoundException('Interest set not found.');
        }

        $tags = array_values(array_unique(array_map('strtolower', $set['tags'] ?? [])));
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        $articles = !empty($tags)
            ? $contentSearch->searchByTopics($tags, limit: $perPage * 10)
            : [];

        $pager = new Pagerfanta(new ArrayAdapter($articles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);

        return $this->render('forum/interest_set.html.twig', [
            'readingNookNav' => $this->buildReadingNookNav(),
            'set' => $set,
            'articles' => array_slice($articles, ($page - 1) * $perPage, $perPage),
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
        CacheInterface $cache,
        Request $request,
    ): Response
    {
        $catKey = strtolower(trim($topic));
        if (!isset(ForumTopics::TOPICS[$catKey])) {
            throw $this->createNotFoundException('Main topic not found');
        }

        $category = ForumTopics::TOPICS[$catKey];
        $tags = [];
        foreach (($category['subcategories'] ?? []) as $sub) {
            foreach (($sub['tags'] ?? []) as $tag) {
                $tags[] = (string) $tag;
            }
        }
        $tags = array_values(array_unique(array_map('strtolower', array_map('trim', $tags))));

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;

        $cacheKey = sprintf('forum.main_topic.v3.%s.page.%d', $catKey, $page);
        $payload = $cache->get($cacheKey, function (ItemInterface $item) use ($contentSearch, $tags, $page, $perPage) {
            $item->expiresAfter(30);

            $offset = ($page - 1) * $perPage;
            // Fetch one extra record as a cheap "has next page" signal.
            $window = $contentSearch->searchByTopics($tags, limit: $perPage + 1, offset: $offset);
            $hasMore = count($window) > $perPage;

            return [
                'articles' => array_slice($window, 0, $perPage),
                'hasMore' => $hasMore,
            ];
        });

        $articlesPage = $payload['articles'] ?? [];
        $hasMore = (bool) ($payload['hasMore'] ?? false);
        $hasPrev = $page > 1;

        return $this->render('forum/main_topic.html.twig', [
            'mainTopicsMap' => $this->buildMainTopicsMap(),
            'categoryKey' => $catKey,
            'category' => ['name' => $category['name'] ?? ucfirst($catKey)],
            'articles' => $articlesPage,
            'page' => $page,
            'hasPrev' => $hasPrev,
            'hasMore' => $hasMore,
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
        EventRepository $eventRepository,
        Request $request,
    ): Response {
        $key = trim($key);
        [$cat, $sub] = array_pad(explode('-', $key, 2), 2, null);
        $cat = strtolower((string) $cat);
        if ($cat !== 'isets') {
            $sub = $sub !== null ? strtolower($sub) : null;
        }

        if ($cat === 'interests' && $sub === 'all') {
            $allTags = [];
            /** @var User|null $user */
            $user = $this->getUser();
            if ($user) {
                try {
                    $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                    $interests = $nostrClient->getUserInterests($pubkey);
                    if (!empty($interests)) {
                        $allTags = array_map('strtolower', array_values($interests));
                    }
                } catch (\Throwable) {
                }
            }
            $topic = [
                'name' => 'All Interests',
                'tags' => $allTags,
            ];
        } elseif ($cat === 'isets' && $sub !== null) {
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
        } elseif (!$cat || !$sub || !isset(ForumTopics::TOPICS[$cat]['subcategories'][$sub])) {
            throw $this->createNotFoundException('Topic not found');
        } else {
            $topic = ForumTopics::TOPICS[$cat]['subcategories'][$sub];
        }

        $tags = array_map('strval', $topic['tags'] ?? []);
        $tagCounts = $contentSearch->getTopicsMetadata($tags);

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        $articles = $contentSearch->searchByTopics($tags, limit: $perPage * 10);
        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);

        $pager = new Pagerfanta(new ArrayAdapter($articles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);

        return $this->render('forum/topic.html.twig', [
            'categoryKey' => $cat,
            'subcategoryKey' => $sub,
            'topic' => $topic,
            'tags' => $tagCounts,
            'articles' => $articlesPage,
            'pager' => $pager,
            'topics' => $contentSearch->buildTaxonomyWithCounts(ForumTopics::TOPICS),
            'deprecated' => true,
        ]);
    }

    /**
     * @deprecated Forum tag pages are being replaced by home feed topic integration.
     */
    #[Route('/forum/tag/{tag}', name: 'forum_tag')]
    public function tag(string $tag, ContentSearchService $contentSearch, Request $request): Response
    {
        $tag = strtolower(trim($tag));
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        $articles = $contentSearch->searchByTopics([$tag], limit: $perPage * 10);
        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);

        $pager = new Pagerfanta(new ArrayAdapter($articles));
        $pager->setMaxPerPage($perPage);
        $pager->setCurrentPage($page);

        return $this->render('forum/tag.html.twig', [
            'tag' => $tag,
            'articles' => $articlesPage,
            'pager' => $pager,
            'topics' => $contentSearch->buildTaxonomyWithCounts(ForumTopics::TOPICS),
            'deprecated' => true,
        ]);
    }

    #[Route('/api/interests/current-tags', name: 'api_interests_current_tags', methods: ['GET'])]
    public function currentInterestTags(EventRepository $eventRepository): JsonResponse
    {
        /** @var User|null $user */
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
        } catch (\Throwable) {
            return new JsonResponse(['tags' => []]);
        }
    }

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
            if (!isset($signedEvent['id'], $signedEvent['pubkey'], $signedEvent['created_at'], $signedEvent['kind'], $signedEvent['tags'], $signedEvent['sig'])) {
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
                $isSuccess ? $successCount++ : $failCount++;
                $relayStatuses[] = [
                    'relay' => $relayUrl,
                    'success' => $isSuccess,
                ];
            }

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
                'error' => 'Failed to publish interests: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ---------- Private helpers ----------

    private function buildMainTopicsMap(): array
    {
        $mainTopicsMap = [];
        foreach (ForumTopics::TOPICS as $key => $data) {
            $mainTopicsMap[$key] = $data['name'] ?? ucfirst($key);
        }
        return $mainTopicsMap;
    }

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
            } catch (\Throwable) {
                $interestSets = [];
            }

            if (!empty($interestSets)) {
                $allSetTags = [];
                foreach ($interestSets as $set) {
                    foreach (($set['tags'] ?? []) as $tag) {
                        $allSetTags[strtolower((string) $tag)] = true;
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
                    foreach (($set['tags'] ?? []) as $tag) {
                        $sum += $setCounts[strtolower((string) $tag)] ?? 0;
                    }
                    $userInterests['isets']['subcategories'][$subKey] = [
                        'name' => $set['title'] ?? $set['dTag'],
                        'tags' => $set['tags'] ?? [],
                        'count' => $sum,
                        'followed' => $set['followed'] ?? false,
                        'owned' => $set['owned'] ?? false,
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
                    $userInterests['interests']['subcategories']['all']['count'] += $counts[strtolower((string) $tag)] ?? 0;
                }
            }

            return !empty($userInterests) ? $userInterests : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

