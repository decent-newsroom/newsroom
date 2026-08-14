<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Entity\Event as StoredEvent;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Helper\NavigationBuilderTrait;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserProfileService;
use App\Service\Nostr\UserRelayListService;
use App\Service\Search\ContentSearchService;
use App\Util\ForumTopics;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;

use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ForumController extends AbstractController
{
    use NavigationBuilderTrait;

    #[Route('/topics', name: 'topics', methods: ['GET'])]
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
        $selectedMain = strtolower(trim((string) $request->query->get('main', '')));
        $selectedTag = strtolower(trim((string) $request->query->get('tag', '')));
        $selectedLabel = '';
        $selectedTags = [];

        if ($selectedTopic !== '' && isset($topics[$selectedTopic])) {
            $selectedLabel = $topics[$selectedTopic]['name'];
            $selectedTags = $topics[$selectedTopic]['tags'];
        } elseif ($selectedMain !== '' && isset(ForumTopics::TOPICS[$selectedMain])) {
            $category = ForumTopics::TOPICS[$selectedMain];
            $selectedLabel = $category['name'] ?? ucfirst($selectedMain);
            foreach (($category['subcategories'] ?? []) as $sub) {
                foreach (($sub['tags'] ?? []) as $tag) {
                    $selectedTags[] = (string) $tag;
                }
            }
            $selectedTags = array_values(array_unique(array_map('strtolower', array_map('trim', $selectedTags))));
        } elseif ($selectedTag !== '') {
            $selectedLabel = '#' . $selectedTag;
            $selectedTags = [$selectedTag];
        }

        $articles = [];
        if ($selectedTags !== []) {
            $articles = $contentSearch->searchByTopics($selectedTags, limit: 20);
        }

        return $this->render('pages/topics.html.twig', [
            'topics' => $topics,
            'selectedTopic' => $selectedTopic,
            'selectedMain' => $selectedMain,
            'selectedTag' => $selectedTag,
            'selectedLabel' => $selectedLabel,
            'articles' => $articles,
        ]);
    }

    /**
     * @deprecated Forum index is being replaced by topics integrated into home feeds.
     */
    #[Route('/forum', name: 'forum', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('topics', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * My Interests - shows only the topics matching the user's interest tags (kind 10015).
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
            return $this->redirectToRoute('topics');
        }

        $currentInterestTags = [];
        try {
            $pubkey = (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ($user->getUserIdentifier()));
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
     * Edit an owned kind:30015 interest set.
     */
    #[Route('/my-interests/set/{dTag}/edit', name: 'interest_set_edit', requirements: ['dTag' => '.+'])]
    public function interestSetEdit(
        string $dTag,
        EventRepository $eventRepository,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('my_interests');
        }

        $pubkeyHex = (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ($user->getUserIdentifier()));
        $event = $eventRepository->findByNaddr(KindsEnum::INTEREST_SETS->value, $pubkeyHex, $dTag);
        if (!$event instanceof StoredEvent) {
            throw $this->createNotFoundException('Interest set not found.');
        }

        return $this->render('forum/interest_set_edit.html.twig', [
            'readingNookNav' => $this->buildReadingNookNav(),
            'set' => $this->buildInterestSetPayload($event, $dTag),
            'groupedTags' => ForumTopics::groupedTags(),
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

        $pubkeyHex = (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ($user->getUserIdentifier()));
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
    #[Route('/forum/main/{topic}', name: 'forum_main_topic', methods: ['GET'])]
    public function mainTopic(string $topic): Response
    {
        return $this->redirectToRoute(
            'topics',
            ['main' => strtolower(trim($topic))],
            Response::HTTP_MOVED_PERMANENTLY,
        );
    }

    /**
     * @deprecated Forum topic pages are being replaced by home feed topic integration.
     */
    #[Route('/forum/topic/{key}', name: 'forum_topic', methods: ['GET'])]
    public function topic(string $key): Response
    {
        return $this->redirectToRoute(
            'topics',
            ['topic' => trim($key)],
            Response::HTTP_MOVED_PERMANENTLY,
        );
    }

    /**
     * @deprecated Forum tag pages are being replaced by home feed topic integration.
     */
    #[Route('/forum/tag/{tag}', name: 'forum_tag', methods: ['GET'])]
    public function tag(string $tag): Response
    {
        return $this->redirectToRoute(
            'topics',
            ['tag' => strtolower(trim($tag))],
            Response::HTTP_MOVED_PERMANENTLY,
        );
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
            $pubkey = (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ($user->getUserIdentifier()));
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

    /**
     * @return array{pubkey: string, dTag: string, title: string, tags: string[]}
     */
    private function buildInterestSetPayload(StoredEvent $event, string $routeDTag): array
    {
        $dTag = $event->getSlug() ?: ($event->getDTag() ?: $routeDTag);
        $title = $event->getTitle() ?? $this->firstTagValue($event->getTags(), ['title', 'name']) ?? $dTag;

        return [
            'pubkey' => $event->getPubkey(),
            'dTag' => $dTag,
            'title' => $title,
            'tags' => $this->extractTopicTags($event->getTags()),
        ];
    }

    /**
     * @param array<int, array<int, mixed>> $tags
     * @param string[] $names
     */
    private function firstTagValue(array $tags, array $names): ?string
    {
        foreach ($tags as $tag) {
            if (!is_array($tag) || !isset($tag[0], $tag[1])) {
                continue;
            }

            if (in_array((string) $tag[0], $names, true)) {
                $value = trim((string) $tag[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array<int, mixed>> $tags
     * @return string[]
     */
    private function extractTopicTags(array $tags): array
    {
        $values = [];
        foreach ($tags as $tag) {
            if (!is_array($tag) || ($tag[0] ?? null) !== 't' || !isset($tag[1])) {
                continue;
            }

            $value = strtolower(trim((string) $tag[1]));
            if ($value !== '') {
                $values[$value] = true;
            }
        }

        ksort($values);

        return array_keys($values);
    }

    private function buildUserInterests(User $user, NostrClient $nostrClient, ContentSearchService $contentSearch, ?array $prefetchedInterests = null): ?array
    {
        try {
            $pubkey = (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ($user->getUserIdentifier()));
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
