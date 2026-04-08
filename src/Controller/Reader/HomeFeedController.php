<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Entity\User;
use App\Enum\FollowPackPurpose;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\FollowPackService;
use App\Service\LatestArticles\LatestArticlesExclusionPolicy;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserProfileService;
use App\Service\MutedPubkeysService;
use App\Service\Search\ArticleSearchFactory;
use App\Service\Search\ArticleSearchInterface;
use App\Service\UserMuteListService;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use swentel\nostr\Nip19\Nip19Helper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeFeedController extends AbstractController
{
    #[Route('/home/tab/{tab}', name: 'home_feed_tab', requirements: ['tab' => 'latest|follows|interests|podcasts|newsbots|discussed|foryou|articles|media'])]
    public function tab(
        string $tab,
        RedisCacheService $redisCacheService,
        RedisViewStore $viewStore,
        LatestArticlesExclusionPolicy $exclusionPolicy,
        ArticleSearchFactory $articleSearchFactory,
        ArticleRepository $articleRepository,
        EventRepository $eventRepository,
        NostrClient $nostrClient,
        UserProfileService $userProfileService,
        FollowPackService $followPackService,
        UserMuteListService $userMuteListService,
        MutedPubkeysService $mutedPubkeysService,
        LoggerInterface $logger,
    ): Response {
        return match ($tab) {
            'latest' => $this->latestTab($redisCacheService, $viewStore, $exclusionPolicy, $articleSearchFactory, $userMuteListService),
            'follows' => $this->followsTab($articleRepository, $eventRepository, $userProfileService, $redisCacheService, $logger),
            'interests' => $this->interestsTab($articleSearchFactory->create(), $nostrClient, $logger),
            'podcasts' => $this->followPackTab(FollowPackPurpose::PODCASTS, $followPackService),
            'newsbots' => $this->followPackTab(FollowPackPurpose::NEWS_BOTS, $followPackService),
            'discussed' => $this->discussedTab($articleRepository, $redisCacheService, $exclusionPolicy, $userMuteListService),
            'foryou', 'articles' => $this->articlesTab($articleRepository, $eventRepository, $userProfileService, $redisCacheService, $exclusionPolicy, $articleSearchFactory, $nostrClient, $userMuteListService, $logger),
            'media' => $this->mediaTab($eventRepository, $nostrClient, $userProfileService, $mutedPubkeysService, $userMuteListService, $logger),
        };
    }

    private function latestTab(
        RedisCacheService $redisCacheService,
        RedisViewStore $viewStore,
        LatestArticlesExclusionPolicy $exclusionPolicy,
        ArticleSearchFactory $articleSearchFactory,
        UserMuteListService $userMuteListService,
    ): Response {
        // ── Resolve user-level mute list (kind 10000, NIP-51) ──
        $userMutedPubkeys = [];
        /** @var User|null $user */
        $user = $this->getUser();
        if ($user) {
            try {
                $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                $userMutedPubkeys = $userMuteListService->getMutedPubkeys($pubkeyHex);
            } catch (\Throwable) {
                // Non-critical — proceed without user mutes
            }
        }

        $cachedView = $viewStore->fetchLatestArticles();

        if ($cachedView !== null) {
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
            // Unified exclusion: config-level deny-list + admin-muted + user-muted
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
            $metaRaw = $redisCacheService->getMultipleMetadata($authorPubkeys);
            $authorsMetadata = [];
            foreach ($metaRaw as $pk => $m) {
                $authorsMetadata[$pk] = $m instanceof UserMetadata ? $m->toStdClass() : $m;
            }
        }

        return $this->render('home/tabs/_latest.html.twig', [
            'articles' => $articles,
            'authorsMetadata' => $authorsMetadata,
        ]);
    }

    private function followsTab(
        ArticleRepository $articleRepository,
        EventRepository $eventRepository,
        UserProfileService $userProfileService,
        RedisCacheService $redisCacheService,
        LoggerInterface $logger,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->render('home/tabs/_follows.html.twig', [
                'articles' => [],
                'authorsMetadata' => [],
                'isLoggedIn' => false,
            ]);
        }

        try {
            $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        } catch (\Throwable $e) {
            $logger->error('Failed to convert npub to hex for follows tab', ['error' => $e->getMessage()]);
            return $this->render('home/tabs/_follows.html.twig', [
                'articles' => [],
                'authorsMetadata' => [],
                'isLoggedIn' => true,
                'error' => 'Unable to process credentials',
            ]);
        }

        // ── 1. Resolve followed pubkeys from local DB ──
        // The kind 3 event is synced on login via SyncUserEventsHandler.
        // If it's not in the DB yet, try a one-time backfill from the relay
        // (fast — local strfry is checked first).
        $followedPubkeys = [];
        try {
            $followsEvent = $eventRepository->findLatestByPubkeyAndKind($pubkeyHex, KindsEnum::FOLLOWS->value);

            // DB miss: backfill from relay (local strfry first, then user's NIP-65 relays)
            if ($followsEvent === null) {
                $logger->info('Kind 3 not in DB, attempting relay backfill', ['pubkey' => substr($pubkeyHex, 0, 8) . '...']);
                $followedPubkeys = $userProfileService->getFollows($pubkeyHex);
            } else {
                foreach ($followsEvent->getTags() as $tag) {
                    if (is_array($tag) && ($tag[0] ?? '') === 'p' && isset($tag[1])) {
                        $followedPubkeys[] = $tag[1];
                    }
                }
            }
        } catch (\Throwable $e) {
            $logger->error('Failed to load follows', ['error' => $e->getMessage()]);
        }

        if (empty($followedPubkeys)) {
            return $this->render('home/tabs/_follows.html.twig', [
                'articles' => [],
                'authorsMetadata' => [],
                'isLoggedIn' => true,
                'followCount' => 0,
            ]);
        }

        // ── 2. Query articles from local DB using indexed lookup ──
        $articles = $articleRepository->findLatestByPubkeys($followedPubkeys, 50);

        // ── 3. Batch-resolve author metadata from Redis cache ──
        $authorsMetadata = [];
        $authorPubkeys = array_unique(array_map(fn(Article $a) => $a->getPubkey(), $articles));
        if (!empty($authorPubkeys)) {
            $metadataMap = $redisCacheService->getMultipleMetadata($authorPubkeys);
            foreach ($metadataMap as $pk => $metadata) {
                $authorsMetadata[$pk] = $metadata instanceof UserMetadata ? $metadata->toStdClass() : $metadata;
            }
        }

        return $this->render('home/tabs/_follows.html.twig', [
            'articles' => $articles,
            'authorsMetadata' => $authorsMetadata,
            'isLoggedIn' => true,
            'followCount' => count($followedPubkeys),
        ]);
    }

    private function interestsTab(
        ArticleSearchInterface $articleSearch,
        NostrClient $nostrClient,
        LoggerInterface $logger,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->render('home/tabs/_interests.html.twig', [
                'articles' => [],
                'isLoggedIn' => false,
            ]);
        }

        $interestTags = [];
        try {
            $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
            $interestTags = $nostrClient->getUserInterests($pubkey);
        } catch (\Throwable $e) {
            $logger->error('Failed to fetch interests for home tab', ['error' => $e->getMessage()]);
        }

        $articles = [];
        if (!empty($interestTags) && $articleSearch->isAvailable()) {
            try {
                $articles = $articleSearch->findByTopics($interestTags, 50, 0);

                // Deduplicate
                $seen = [];
                $articles = array_values(array_filter($articles, function ($a) use (&$seen) {
                    $pubkey = $a instanceof Article ? $a->getPubkey() : (method_exists($a, 'getPubkey') ? $a->getPubkey() : '');
                    $slug = $a instanceof Article ? $a->getSlug() : (method_exists($a, 'getSlug') ? $a->getSlug() : '');
                    $key = $pubkey . ':' . $slug;
                    if (isset($seen[$key])) return false;
                    $seen[$key] = true;
                    return true;
                }));
            } catch (\Throwable $e) {
                $logger->error('Failed to search interest articles', ['error' => $e->getMessage()]);
            }
        }

        return $this->render('home/tabs/_interests.html.twig', [
            'articles' => $articles,
            'isLoggedIn' => true,
            'interestTags' => $interestTags,
        ]);
    }

    private function followPackTab(
        FollowPackPurpose $purpose,
        FollowPackService $followPackService,
    ): Response {
        $result = $followPackService->getArticlesForPurpose($purpose);

        return $this->render('home/tabs/_followpack.html.twig', [
            'articles' => $result['articles'],
            'authorsMetadata' => $result['authorsMetadata'],
            'purpose' => $purpose,
        ]);
    }

    private function discussedTab(
        ArticleRepository $articleRepository,
        RedisCacheService $redisCacheService,
        LatestArticlesExclusionPolicy $exclusionPolicy,
        UserMuteListService $userMuteListService,
    ): Response {
        // Resolve user-level mute list
        $userMutedPubkeys = [];
        /** @var User|null $user */
        $user = $this->getUser();
        if ($user) {
            try {
                $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                $userMutedPubkeys = $userMuteListService->getMutedPubkeys($pubkeyHex);
            } catch (\Throwable) {
                // Non-critical
            }
        }

        $excludedPubkeys = array_values(array_unique(array_merge(
            $exclusionPolicy->getAllExcludedPubkeys(),
            $userMutedPubkeys,
        )));

        $result = $articleRepository->findArticlesWithComments(50, $excludedPubkeys);
        $articles = $result['articles'];
        $commentCounts = $result['commentCounts'];

        // Batch-resolve author metadata
        $authorPubkeys = [];
        foreach ($articles as $article) {
            $pk = $article->getPubkey();
            if ($pk && NostrKeyUtil::isHexPubkey($pk)) {
                $authorPubkeys[] = $pk;
            }
        }
        $authorPubkeys = array_unique($authorPubkeys);
        $metaRaw = $redisCacheService->getMultipleMetadata($authorPubkeys);
        $authorsMetadata = [];
        foreach ($metaRaw as $pk => $m) {
            $authorsMetadata[$pk] = $m instanceof UserMetadata ? $m->toStdClass() : $m;
        }

        return $this->render('home/tabs/_discussed.html.twig', [
            'articles' => $articles,
            'authorsMetadata' => $authorsMetadata,
            'commentCounts' => $commentCounts,
        ]);
    }

    /**
     * Combined "Articles" feed: merges discussed, follows, and interests articles
     * into one deduplicated, time-sorted list with source labels.
     */
    private function articlesTab(
        ArticleRepository $articleRepository,
        EventRepository $eventRepository,
        UserProfileService $userProfileService,
        RedisCacheService $redisCacheService,
        LatestArticlesExclusionPolicy $exclusionPolicy,
        ArticleSearchFactory $articleSearchFactory,
        NostrClient $nostrClient,
        UserMuteListService $userMuteListService,
        LoggerInterface $logger,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->render('home/tabs/_articles.html.twig', [
                'articles' => [],
                'authorsMetadata' => [],
                'sourceLabels' => [],
                'commentCounts' => [],
                'isLoggedIn' => false,
            ]);
        }

        // ── Resolve user mute list ──
        $userMutedPubkeys = [];
        $pubkeyHex = null;
        try {
            $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
            $userMutedPubkeys = $userMuteListService->getMutedPubkeys($pubkeyHex);
        } catch (\Throwable) {
            // Non-critical
        }

        $excludedPubkeys = array_values(array_unique(array_merge(
            $exclusionPolicy->getAllExcludedPubkeys(),
            $userMutedPubkeys,
        )));

        // Keyed by coordinate (pubkey:slug) → article object
        $mergedArticles = [];
        // coordinate → array of source labels
        $sourceLabels = [];
        // coordinate → comment count (from discussed)
        $commentCounts = [];
        // All author metadata
        $authorsMetadata = [];

        // ── 1. Discussed articles ──
        try {
            $result = $articleRepository->findArticlesWithComments(50, $excludedPubkeys);
            foreach ($result['articles'] as $article) {
                $pk = $article->getPubkey();
                $slug = $article->getSlug();
                if (empty($pk) || empty($slug)) continue;
                $coord = $pk . ':' . $slug;
                if (!isset($mergedArticles[$coord])) {
                    $mergedArticles[$coord] = $article;
                    $sourceLabels[$coord] = [];
                }
                $sourceLabels[$coord][] = 'discussed';
                $commentCounts[$coord] = $result['commentCounts']['30023:' . $coord] ?? 0;
            }
        } catch (\Throwable $e) {
            $logger->error('For-you: failed to load discussed articles', ['error' => $e->getMessage()]);
        }

        // ── 2. Follows articles ──
        if ($pubkeyHex) {
            try {
                $followedPubkeys = [];
                $followsEvent = $eventRepository->findLatestByPubkeyAndKind($pubkeyHex, KindsEnum::FOLLOWS->value);
                if ($followsEvent === null) {
                    $followedPubkeys = $userProfileService->getFollows($pubkeyHex);
                } else {
                    foreach ($followsEvent->getTags() as $tag) {
                        if (is_array($tag) && ($tag[0] ?? '') === 'p' && isset($tag[1])) {
                            $followedPubkeys[] = $tag[1];
                        }
                    }
                }

                if (!empty($followedPubkeys)) {
                    $followArticles = $articleRepository->findLatestByPubkeys($followedPubkeys, 50);
                    foreach ($followArticles as $article) {
                        $pk = $article->getPubkey();
                        $slug = $article->getSlug();
                        if (empty($pk) || empty($slug)) continue;
                        $coord = $pk . ':' . $slug;
                        if (!isset($mergedArticles[$coord])) {
                            $mergedArticles[$coord] = $article;
                            $sourceLabels[$coord] = [];
                        }
                        if (!in_array('follows', $sourceLabels[$coord], true)) {
                            $sourceLabels[$coord][] = 'follows';
                        }
                    }
                }
            } catch (\Throwable $e) {
                $logger->error('For-you: failed to load follows articles', ['error' => $e->getMessage()]);
            }
        }

        // ── 3. Interests articles ──
        if ($pubkeyHex) {
            try {
                $interestTags = $nostrClient->getUserInterests($pubkeyHex);
                if (!empty($interestTags)) {
                    $articleSearch = $articleSearchFactory->create();
                    if ($articleSearch->isAvailable()) {
                        $interestArticles = $articleSearch->findByTopics($interestTags, 50, 0);
                        foreach ($interestArticles as $article) {
                            $pk = $article instanceof Article ? $article->getPubkey() : (method_exists($article, 'getPubkey') ? $article->getPubkey() : '');
                            $slug = $article instanceof Article ? $article->getSlug() : (method_exists($article, 'getSlug') ? $article->getSlug() : '');
                            if (empty($pk) || empty($slug)) continue;
                            // Filter out muted pubkeys
                            if (in_array($pk, $excludedPubkeys, true)) continue;
                            $coord = $pk . ':' . $slug;
                            if (!isset($mergedArticles[$coord])) {
                                $mergedArticles[$coord] = $article;
                                $sourceLabels[$coord] = [];
                            }
                            if (!in_array('interests', $sourceLabels[$coord], true)) {
                                $sourceLabels[$coord][] = 'interests';
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $logger->error('For-you: failed to load interest articles', ['error' => $e->getMessage()]);
            }
        }

        // ── 4. Sort merged articles by createdAt descending ──
        $articlesArray = array_values($mergedArticles);
        usort($articlesArray, function ($a, $b) {
            $aTime = $a instanceof Article ? $a->getCreatedAt() : ($a->createdAt ?? 0);
            $bTime = $b instanceof Article ? $b->getCreatedAt() : ($b->createdAt ?? 0);
            return $bTime <=> $aTime;
        });

        // Limit to 60 items
        $articlesArray = array_slice($articlesArray, 0, 60);

        // ── 5. Batch-resolve author metadata ──
        $authorPubkeys = [];
        foreach ($articlesArray as $article) {
            $pk = $article instanceof Article ? $article->getPubkey() : ($article->pubkey ?? '');
            if ($pk && NostrKeyUtil::isHexPubkey($pk)) {
                $authorPubkeys[] = $pk;
            }
        }
        $authorPubkeys = array_unique($authorPubkeys);
        if (!empty($authorPubkeys)) {
            $metaRaw = $redisCacheService->getMultipleMetadata($authorPubkeys);
            foreach ($metaRaw as $pk => $m) {
                $authorsMetadata[$pk] = $m instanceof UserMetadata ? $m->toStdClass() : $m;
            }
        }

        return $this->render('home/tabs/_articles.html.twig', [
            'articles' => $articlesArray,
            'authorsMetadata' => $authorsMetadata,
            'sourceLabels' => $sourceLabels,
            'commentCounts' => $commentCounts,
            'isLoggedIn' => true,
        ]);
    }

    /**
     * Media feed: non-NSFW media events (kinds 20, 21, 22, 34235, 34236) filtered
     * to followed pubkeys and interest hashtags, merged and deduplicated.
     */
    private function mediaTab(
        EventRepository $eventRepository,
        NostrClient $nostrClient,
        UserProfileService $userProfileService,
        MutedPubkeysService $mutedPubkeysService,
        UserMuteListService $userMuteListService,
        LoggerInterface $logger,
    ): Response {
        $mediaKinds = [20, 21, 22, 34235, 34236];
        $maxItems = 42;

        // ── Resolve excluded pubkeys (admin-level + user-level) ──
        $excludedPubkeys = $mutedPubkeysService->getMutedPubkeys();
        $pubkeyHex = null;
        /** @var User|null $user */
        $user = $this->getUser();
        if ($user) {
            try {
                $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                $userMutedPubkeys = $userMuteListService->getMutedPubkeys($pubkeyHex);
                $excludedPubkeys = array_values(array_unique(array_merge($excludedPubkeys, $userMutedPubkeys)));
            } catch (\Throwable) {
                // Non-critical
            }
        }

        // Deduplicate by event ID
        $mergedEvents = []; // id → Event entity

        // ── 1. Follows media ──
        if ($pubkeyHex) {
            try {
                $followedPubkeys = [];
                $followsEvent = $eventRepository->findLatestByPubkeyAndKind($pubkeyHex, KindsEnum::FOLLOWS->value);
                if ($followsEvent === null) {
                    $followedPubkeys = $userProfileService->getFollows($pubkeyHex);
                } else {
                    foreach ($followsEvent->getTags() as $tag) {
                        if (is_array($tag) && ($tag[0] ?? '') === 'p' && isset($tag[1])) {
                            $followedPubkeys[] = $tag[1];
                        }
                    }
                }

                if (!empty($followedPubkeys)) {
                    $filteredPubkeys = array_values(array_diff($followedPubkeys, $excludedPubkeys));
                    $followEvents = $eventRepository->findNonNSFWMediaEventsByPubkeys($filteredPubkeys, $mediaKinds, $maxItems);
                    foreach ($followEvents as $event) {
                        $mergedEvents[$event->getId()] = $event;
                    }
                }
            } catch (\Throwable $e) {
                $logger->error('Home media tab: failed to load follows media', ['error' => $e->getMessage()]);
            }
        }

        // ── 2. Interests media ──
        if ($pubkeyHex) {
            try {
                $interestTags = $nostrClient->getUserInterests($pubkeyHex);
                if (!empty($interestTags)) {
                    $interestEvents = $eventRepository->findMediaEventsByHashtags($interestTags, $mediaKinds, $excludedPubkeys, $maxItems * 2);
                    // Filter NSFW
                    $interestEvents = array_filter($interestEvents, fn($e) => !$e->isNSFW());
                    foreach ($interestEvents as $event) {
                        if (!isset($mergedEvents[$event->getId()])) {
                            $mergedEvents[$event->getId()] = $event;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $logger->error('Home media tab: failed to load interests media', ['error' => $e->getMessage()]);
            }
        }

        // ── 3. Sort by created_at descending and limit ──
        $eventsArray = array_values($mergedEvents);
        usort($eventsArray, fn($a, $b) => $b->getCreatedAt() <=> $a->getCreatedAt());
        $eventsArray = array_slice($eventsArray, 0, $maxItems);

        // ── 4. Convert to stdClass for masonry template ──
        $mediaEvents = [];
        $nip19 = new Nip19Helper();
        foreach ($eventsArray as $event) {
            $obj = new \stdClass();
            $obj->id = $event->getId();
            $obj->pubkey = $event->getPubkey();
            $obj->created_at = $event->getCreatedAt();
            $obj->kind = $event->getKind();
            $obj->tags = $event->getTags();
            $obj->content = $event->getContent();
            $obj->sig = $event->getSig();
            $obj->noteId = $nip19->encodeNote($event->getId());
            $mediaEvents[] = $obj;
        }

        return $this->render('home/tabs/_media.html.twig', [
            'mediaEvents' => $mediaEvents,
        ]);
    }
}
