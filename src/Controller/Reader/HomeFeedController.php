<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Entity\User;
use App\Enum\FollowPackPurpose;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\FollowPackService;
use App\Service\MutedPubkeysService;
use App\Service\Nostr\FollowsRelayPoolService;
use App\Service\Nostr\NostrClient;
use App\Service\Search\ArticleSearchFactory;
use App\Service\Search\ArticleSearchInterface;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
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
        MutedPubkeysService $mutedPubkeysService,
        ArticleSearchFactory $articleSearchFactory,
        EntityManagerInterface $em,
        NostrClient $nostrClient,
        FollowPackService $followPackService,
        FollowsRelayPoolService $followsRelayPoolService,
        LoggerInterface $logger,
    ): Response {
        return match ($tab) {
            'latest' => $this->latestTab($redisCacheService, $viewStore, $mutedPubkeysService, $articleSearchFactory),
            'follows' => $this->followsTab($em, $nostrClient, $redisCacheService, $followsRelayPoolService, $logger),
            'interests' => $this->interestsTab($articleSearchFactory->create(), $nostrClient, $logger),
            'podcasts' => $this->followPackTab(FollowPackPurpose::PODCASTS, $followPackService),
            'newsbots' => $this->followPackTab(FollowPackPurpose::NEWS_BOTS, $followPackService),
        };
    }

    private function latestTab(
        RedisCacheService $redisCacheService,
        RedisViewStore $viewStore,
        MutedPubkeysService $mutedPubkeysService,
        ArticleSearchFactory $articleSearchFactory,
    ): Response {
        $cachedView = $viewStore->fetchLatestArticles();

        if ($cachedView !== null) {
            $articles = [];
            $authorsMetadata = [];
            foreach ($cachedView as $baseObject) {
                if (isset($baseObject['article'])) {
                    $articles[] = (object) $baseObject['article'];
                }
                if (isset($baseObject['profiles'])) {
                    foreach ($baseObject['profiles'] as $pubkey => $profile) {
                        $authorsMetadata[$pubkey] = (object) $profile;
                    }
                }
            }
        } else {
            $excludedPubkeys = $mutedPubkeysService->getMutedPubkeys();
            $articleSearch = $articleSearchFactory->create();
            $articles = $articleSearch->findLatest(50, $excludedPubkeys);

            $authorPubkeys = [];
            foreach ($articles as $article) {
                $pk = null;
                if ($article instanceof Article) {
                    $pk = $article->getPubkey();
                } elseif (is_object($article)) {
                    if (method_exists($article, 'getPubkey') && $article->getPubkey() !== null) {
                        $pk = $article->getPubkey();
                    } elseif (isset($article->npub) && NostrKeyUtil::isNpub($article->npub)) {
                        $pk = NostrKeyUtil::npubToHex($article->npub);
                    }
                }
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
        EntityManagerInterface $em,
        NostrClient $nostrClient,
        RedisCacheService $redisCacheService,
        FollowsRelayPoolService $followsRelayPoolService,
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

        $followedPubkeys = [];
        try {
            $followedPubkeys = $nostrClient->getUserFollows($pubkeyHex, $user->getRelays()['all'] ?? null);
        } catch (\Throwable $e) {
            $logger->error('Failed to fetch follows for home tab', ['error' => $e->getMessage()]);
            return $this->render('home/tabs/_follows.html.twig', [
                'articles' => [],
                'authorsMetadata' => [],
                'isLoggedIn' => true,
                'error' => 'Unable to fetch your follow list',
            ]);
        }

        $articles = [];
        $authorsMetadata = [];

        // Resolve the consolidated relay pool for this user's follows
        $followsRelayPool = [];
        try {
            $followsRelayPool = $followsRelayPoolService->getPoolForUser($pubkeyHex);
        } catch (\Throwable $e) {
            $logger->warning('Failed to get follows relay pool', ['error' => $e->getMessage()]);
        }

        if (!empty($followedPubkeys)) {
            $articleRepo = $em->getRepository(Article::class);
            $qb = $articleRepo->createQueryBuilder('a');
            $qb->where($qb->expr()->in('a.pubkey', ':pubkeys'))
                ->setParameter('pubkeys', $followedPubkeys)
                ->orderBy('a.createdAt', 'DESC')
                ->setMaxResults(50);
            $articles = $qb->getQuery()->getResult();

            // Deduplicate by slug+pubkey
            $seen = [];
            $articles = array_values(array_filter($articles, function (Article $a) use (&$seen) {
                $key = $a->getPubkey() . ':' . $a->getSlug();
                if (isset($seen[$key])) return false;
                $seen[$key] = true;
                return true;
            }));

            $authorPubkeys = array_unique(array_map(fn(Article $a) => $a->getPubkey(), $articles));
            if (!empty($authorPubkeys)) {
                $metadataMap = $redisCacheService->getMultipleMetadata($authorPubkeys);
                foreach ($metadataMap as $pk => $metadata) {
                    $authorsMetadata[$pk] = $metadata instanceof UserMetadata ? $metadata->toStdClass() : $metadata;
                }
            }
        }

        return $this->render('home/tabs/_follows.html.twig', [
            'articles' => $articles,
            'authorsMetadata' => $authorsMetadata,
            'isLoggedIn' => true,
            'followCount' => count($followedPubkeys),
            'followsRelayPool' => $followsRelayPool,
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






