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
use App\Service\Search\ArticleSearchFactory;
use App\Service\Search\ArticleSearchInterface;
use App\Service\UserMuteListService;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeFeedController extends AbstractController
{
    #[Route('/home/tab/{tab}', name: 'home_feed_tab', requirements: ['tab' => 'latest|follows|interests|podcasts|newsbots'])]
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
        LoggerInterface $logger,
    ): Response {
        return match ($tab) {
            'latest' => $this->latestTab($redisCacheService, $viewStore, $exclusionPolicy, $articleSearchFactory, $userMuteListService),
            'follows' => $this->followsTab($articleRepository, $eventRepository, $userProfileService, $redisCacheService, $logger),
            'interests' => $this->interestsTab($articleSearchFactory->create(), $nostrClient, $logger),
            'podcasts' => $this->followPackTab(FollowPackPurpose::PODCASTS, $followPackService),
            'newsbots' => $this->followPackTab(FollowPackPurpose::NEWS_BOTS, $followPackService),
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
}
