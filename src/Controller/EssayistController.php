<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\UserMetadata;
use App\Entity\User;
use App\Enum\FollowPackPurpose;
use App\Enum\KindsEnum;
use App\Enum\RolesEnum;
use App\Repository\EventRepository;
use App\Repository\FollowPackSourceRepository;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Essayist\EssayistFeedService;
use App\Service\Essayist\EssayistMembershipCacheService;
use App\Service\FollowPackService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserProfileService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/essayist')]
class EssayistController extends AbstractController
{
    private const LAUNCH_DATE         = '2026-06-01T00:00:00+00:00';
    private const EARLY_BIRD_DEADLINE = '2026-06-01T00:00:00+00:00';

    #[Route('', name: 'app_static_essayist', methods: ['GET'])]
    public function index(Request $request, UserEntityRepository $userRepository): Response
    {
        $user  = $this->getUser();
        $roles = $user instanceof User ? $user->getRoles() : [];

        $launchDate = new \DateTimeImmutable(self::LAUNCH_DATE);
        $now        = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->render('static/essayist.html.twig', [
            'isMember'          => in_array(RolesEnum::ESSAYIST_MEMBER->value, $roles, true),
            'isPending'         => in_array(RolesEnum::ESSAYIST_CANDIDATE->value, $roles, true),
            'isEarlyBird'       => in_array(RolesEnum::ESSAYIST_EARLY_BIRD->value, $roles, true),
            'isAdmin'           => in_array('ROLE_ADMIN', $roles, true),
            'memberCount'       => $userRepository->countByRole(RolesEnum::ESSAYIST_MEMBER->value),
            'joinStatus'        => $request->query->get('join_status'),
            'launchDate'        => $launchDate,
            'isLaunched'        => $now >= $launchDate,
            'earlyBirdDeadline' => new \DateTimeImmutable(self::EARLY_BIRD_DEADLINE),
        ]);
    }

    #[Route('/early-bird', name: 'app_static_essayist_early_bird', methods: ['POST'])]
    public function claimEarlyBird(
        Request $request,
        EntityManagerInterface $em,
        EssayistMembershipCacheService $membershipCache,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'login_required']);
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('essayist_early_bird', $token)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'invalid_csrf']);
        }

        if (in_array(RolesEnum::ESSAYIST_EARLY_BIRD->value, $user->getRoles(), true)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'already_early_bird']);
        }

        $user->addRole(RolesEnum::ESSAYIST_EARLY_BIRD->value);
        $user->addRole(RolesEnum::ESSAYIST_MEMBER->value);
        $em->persist($user);
        $em->flush();

        $membershipCache->markApproved((string) $user->getNpub());

        return $this->redirectToRoute('app_static_essayist', ['join_status' => 'early_bird_claimed']);
    }

    #[Route('/request-access', name: 'app_static_essayist_request_access', methods: ['POST'])]
    public function requestAccess(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'login_required']);
        }

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('essayist_request_access', $token)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'invalid_csrf']);
        }

        $roles = $user->getRoles();

        if (in_array(RolesEnum::ESSAYIST_MEMBER->value, $roles, true)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'already_member']);
        }

        if (in_array(RolesEnum::ESSAYIST_CANDIDATE->value, $roles, true)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'already_pending']);
        }

        $user->addRole(RolesEnum::ESSAYIST_CANDIDATE->value);
        $em->persist($user);
        $em->flush();

        return $this->redirectToRoute('app_static_essayist', ['join_status' => 'request_created']);
    }

    /**
     * Members-only article feed — queries strfry-essayist for the latest kind:30023 articles.
     * Admins can access as a preview regardless of membership status.
     */
    #[Route('/feed', name: 'app_essayist_feed', methods: ['GET'])]
    public function feed(
        EssayistFeedService $feedService,
        RedisCacheService $redisCacheService,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'login_required']);
        }

        $roles   = $user->getRoles();
        $isAdmin = in_array('ROLE_ADMIN', $roles, true);

        if (!$isAdmin && !in_array(RolesEnum::ESSAYIST_MEMBER->value, $roles, true)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'access_denied']);
        }

        $articles = $feedService->fetchLatest(50);

        // Resolve author metadata from Redis for avatars / display names
        $authorsMetadata = [];
        if (!empty($articles)) {
            $pubkeys = array_unique(array_column($articles, 'pubkey'));
            $pubkeys = array_filter($pubkeys, fn (string $pk): bool => NostrKeyUtil::isHexPubkey($pk));
            if (!empty($pubkeys)) {
                $raw = $redisCacheService->getMultipleMetadata(array_values($pubkeys));
                foreach ($raw as $pk => $meta) {
                    $authorsMetadata[$pk] = is_object($meta) && method_exists($meta, 'toStdClass')
                        ? $meta->toStdClass()
                        : $meta;
                }
            }
        }

        return $this->render('essayist/feed.html.twig', [
            'articles'        => $articles,
            'authorsMetadata' => $authorsMetadata,
            'isAdmin'         => $isAdmin,
        ]);
    }

    /**
     * Personalized home page for Essayist members.
     * Shows tabs: For You / Follows / Topics, plus a featured writers sidebar.
     */
    #[Route('/home', name: 'app_essayist_home', methods: ['GET'])]
    public function home(
        FollowPackSourceRepository $followPackSourceRepository,
        FollowPackService $followPackService,
        RedisCacheService $redisCacheService,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'login_required']);
        }

        $roles   = $user->getRoles();
        $isAdmin = in_array('ROLE_ADMIN', $roles, true);

        if (!$isAdmin && !in_array(RolesEnum::ESSAYIST_MEMBER->value, $roles, true)) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'access_denied']);
        }

        // ── Featured ESSAYIST_WRITERS follow pack ──
        $featuredPackInfo    = null;
        $featuredPackMembers = [];

        try {
            $packSource = $followPackSourceRepository->findByPurpose(FollowPackPurpose::ESSAYIST_WRITERS);
            if ($packSource) {
                $pubkeys = $followPackService->getPubkeysFromCoordinate($packSource->getCoordinate());
                if (!empty($pubkeys)) {
                    $metaMap = $redisCacheService->getMultipleMetadata($pubkeys);
                    foreach ($pubkeys as $hex) {
                        $meta = $metaMap[$hex] ?? null;
                        $std  = $meta ? ($meta instanceof UserMetadata ? $meta->toStdClass() : $meta) : null;
                        $featuredPackMembers[] = [
                            'pubkey'      => $hex,
                            'npub'        => NostrKeyUtil::hexToNpub($hex),
                            'displayName' => $std?->display_name ?? $std?->name ?? '',
                            'picture'     => $std?->picture ?? '',
                            'nip05'       => (is_array($std?->nip05 ?? '') ? ($std->nip05[0] ?? '') : ($std?->nip05 ?? '')),
                        ];
                    }
                    // Extract pack coordinate parts for link generation
                    $parts = explode(':', $packSource->getCoordinate(), 3);
                    if (count($parts) === 3) {
                        $featuredPackInfo = [
                            'coordinate' => $packSource->getCoordinate(),
                            'npub'       => NostrKeyUtil::hexToNpub($parts[1]),
                            'dtag'       => $parts[2],
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // Non-critical — proceed without featured pack
        }

        return $this->render('essayist/home.html.twig', [
            'isAdmin'             => $isAdmin,
            'featuredPackInfo'    => $featuredPackInfo,
            'featuredPackMembers' => $featuredPackMembers,
        ]);
    }

    /**
     * Turbo Frame tab endpoint for the Essayist personalized home page.
     */
    #[Route('/home/tab/{tab}', name: 'app_essayist_home_tab', requirements: ['tab' => 'foryou|follows|topics'])]
    public function homeFeedTab(
        string $tab,
        EssayistFeedService $feedService,
        EventRepository $eventRepository,
        UserProfileService $userProfileService,
        NostrClient $nostrClient,
        RedisCacheService $redisCacheService,
        LoggerInterface $logger,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->render('essayist/tabs/_foryou.html.twig', [
                'articles'        => [],
                'authorsMetadata' => [],
                'isLoggedIn'      => false,
            ]);
        }

        $roles   = $user->getRoles();
        $isAdmin = in_array('ROLE_ADMIN', $roles, true);

        if (!$isAdmin && !in_array(RolesEnum::ESSAYIST_MEMBER->value, $roles, true)) {
            return $this->render('essayist/tabs/_foryou.html.twig', [
                'articles'        => [],
                'authorsMetadata' => [],
                'isLoggedIn'      => true,
                'accessDenied'    => true,
            ]);
        }

        $pubkeyHex = null;
        try {
            $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        } catch (\Throwable $e) {
            $logger->error('EssayistHome: failed to convert npub', ['error' => $e->getMessage()]);
        }

        return match ($tab) {
            'follows' => $this->essayistFollowsTab($feedService, $eventRepository, $userProfileService, $redisCacheService, $logger, $pubkeyHex),
            'topics'  => $this->essayistTopicsTab($feedService, $nostrClient, $redisCacheService, $logger, $pubkeyHex),
            default   => $this->essayistForYouTab($feedService, $eventRepository, $userProfileService, $nostrClient, $redisCacheService, $logger, $pubkeyHex),
        };
    }

    // ── Private tab helpers ────────────────────────────────────────────────

    private function essayistFollowsTab(
        EssayistFeedService $feedService,
        EventRepository $eventRepository,
        UserProfileService $userProfileService,
        RedisCacheService $redisCacheService,
        LoggerInterface $logger,
        ?string $pubkeyHex,
    ): Response {
        if (!$pubkeyHex) {
            return $this->render('essayist/tabs/_follows.html.twig', [
                'articles'        => [],
                'authorsMetadata' => [],
                'isLoggedIn'      => true,
                'followCount'     => 0,
            ]);
        }

        $followedPubkeys = [];
        try {
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
        } catch (\Throwable $e) {
            $logger->error('EssayistHome follows tab: failed to load follows', ['error' => $e->getMessage()]);
        }

        if (empty($followedPubkeys)) {
            return $this->render('essayist/tabs/_follows.html.twig', [
                'articles'        => [],
                'authorsMetadata' => [],
                'isLoggedIn'      => true,
                'followCount'     => 0,
            ]);
        }

        $articles = $feedService->fetchByPubkeys($followedPubkeys, 50);

        $authorsMetadata = $this->resolveMetadata(array_column($articles, 'pubkey'), $redisCacheService);

        return $this->render('essayist/tabs/_follows.html.twig', [
            'articles'        => $articles,
            'authorsMetadata' => $authorsMetadata,
            'isLoggedIn'      => true,
            'followCount'     => count($followedPubkeys),
        ]);
    }

    private function essayistTopicsTab(
        EssayistFeedService $feedService,
        NostrClient $nostrClient,
        RedisCacheService $redisCacheService,
        LoggerInterface $logger,
        ?string $pubkeyHex,
    ): Response {
        if (!$pubkeyHex) {
            return $this->render('essayist/tabs/_topics.html.twig', [
                'articles'     => [],
                'interestTags' => [],
                'isLoggedIn'   => false,
            ]);
        }

        $interestTags = [];
        try {
            $interestTags = $nostrClient->getUserInterests($pubkeyHex);
        } catch (\Throwable $e) {
            $logger->error('EssayistHome topics tab: failed to fetch interests', ['error' => $e->getMessage()]);
        }

        if (empty($interestTags)) {
            return $this->render('essayist/tabs/_topics.html.twig', [
                'articles'     => [],
                'interestTags' => [],
                'isLoggedIn'   => true,
            ]);
        }

        $articles = $feedService->fetchByTopics($interestTags, 50);

        $authorsMetadata = $this->resolveMetadata(array_column($articles, 'pubkey'), $redisCacheService);

        return $this->render('essayist/tabs/_topics.html.twig', [
            'articles'        => $articles,
            'authorsMetadata' => $authorsMetadata,
            'interestTags'    => $interestTags,
            'isLoggedIn'      => true,
        ]);
    }

    private function essayistForYouTab(
        EssayistFeedService $feedService,
        EventRepository $eventRepository,
        UserProfileService $userProfileService,
        NostrClient $nostrClient,
        RedisCacheService $redisCacheService,
        LoggerInterface $logger,
        ?string $pubkeyHex,
    ): Response {
        if (!$pubkeyHex) {
            return $this->render('essayist/tabs/_foryou.html.twig', [
                'articles'        => [],
                'authorsMetadata' => [],
                'sourceLabels'    => [],
                'isLoggedIn'      => false,
            ]);
        }

        // coordinate → article card
        $mergedArticles = [];
        // coordinate → source label array
        $sourceLabels = [];

        // ── 1. Follows articles from Essayist relay ──
        try {
            $followedPubkeys = [];
            $followsEvent    = $eventRepository->findLatestByPubkeyAndKind($pubkeyHex, KindsEnum::FOLLOWS->value);
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
                $followArticles = $feedService->fetchByPubkeys($followedPubkeys, 60);
                foreach ($followArticles as $card) {
                    $coord = $card->pubkey . ':' . $card->slug;
                    if (!isset($mergedArticles[$coord])) {
                        $mergedArticles[$coord] = $card;
                        $sourceLabels[$coord]   = [];
                    }
                    if (!in_array('follows', $sourceLabels[$coord], true)) {
                        $sourceLabels[$coord][] = 'follows';
                    }
                }
            }
        } catch (\Throwable $e) {
            $logger->error('EssayistHome for-you: failed to load follows', ['error' => $e->getMessage()]);
        }

        // ── 2. Topics articles from Essayist relay ──
        try {
            $interestTags = $nostrClient->getUserInterests($pubkeyHex);
            if (!empty($interestTags)) {
                $topicArticles = $feedService->fetchByTopics($interestTags, 60);
                foreach ($topicArticles as $card) {
                    $coord = $card->pubkey . ':' . $card->slug;
                    if (!isset($mergedArticles[$coord])) {
                        $mergedArticles[$coord] = $card;
                        $sourceLabels[$coord]   = [];
                    }
                    if (!in_array('interests', $sourceLabels[$coord], true)) {
                        $sourceLabels[$coord][] = 'interests';
                    }
                }
            }
        } catch (\Throwable $e) {
            $logger->error('EssayistHome for-you: failed to load topics', ['error' => $e->getMessage()]);
        }

        // ── 3. Sort merged articles by createdAt descending ──
        $articlesArray = array_values($mergedArticles);
        usort($articlesArray, fn (object $a, object $b): int => $b->createdAt <=> $a->createdAt);
        $articlesArray = array_slice($articlesArray, 0, 60);

        $authorsMetadata = $this->resolveMetadata(array_column($articlesArray, 'pubkey'), $redisCacheService);

        return $this->render('essayist/tabs/_foryou.html.twig', [
            'articles'        => $articlesArray,
            'authorsMetadata' => $authorsMetadata,
            'sourceLabels'    => $sourceLabels,
            'isLoggedIn'      => true,
        ]);
    }

    /**
     * Batch-resolve author metadata from Redis cache.
     *
     * @param  string[]  $pubkeys
     * @return array<string, \stdClass>
     */
    private function resolveMetadata(array $pubkeys, RedisCacheService $redisCacheService): array
    {
        $pubkeys = array_values(array_unique(array_filter($pubkeys, fn (string $pk): bool => NostrKeyUtil::isHexPubkey($pk))));
        if (empty($pubkeys)) {
            return [];
        }
        $raw  = $redisCacheService->getMultipleMetadata($pubkeys);
        $meta = [];
        foreach ($raw as $pk => $m) {
            $meta[$pk] = $m instanceof UserMetadata ? $m->toStdClass() : $m;
        }
        return $meta;
    }
}

