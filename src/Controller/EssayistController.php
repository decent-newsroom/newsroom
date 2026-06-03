<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\UserMetadata;
use App\Entity\User;
use App\Enum\FollowPackPurpose;
use App\Enum\KindsEnum;
use App\Enum\RolesEnum;
use App\Message\StartRelayFeedMessage;
use App\Repository\EventRepository;
use App\Repository\FollowPackSourceRepository;
use App\Repository\EssayistZapClaimRepository;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Essayist\EssayistFeedService;
use App\Service\Essayist\EssayistMemberActivityService;
use App\Service\Essayist\EssayistMembershipCacheService;
use App\Service\Essayist\EssayistMembershipService;
use App\Service\Essayist\EssayistZapClaimService;
use App\Service\FollowPackService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\RelayFeedBufferService;
use App\Service\Nostr\UserProfileService;
use App\Service\UserMuteListService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
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
     * Members directory — lists current `ROLE_ESSAYIST_MEMBER` users so a
     * prospective contributor can choose a sponsor to zap. Each row exposes
     * the existing `ZapButton` Live Component prefilled with the member's
     * `lud16` lightning address.
     *
     * Access: logged in AND (member | candidate | admin). Anons redirect to
     * the landing page with `join_status=login_required`; logged-in users
     * without one of the gating roles redirect with `join_status=access_denied`.
     */
    #[Route('/members', name: 'app_essayist_members', methods: ['GET'])]
    public function members(
        UserEntityRepository $userRepository,
        RedisCacheService $redisCacheService,
        EssayistMembershipService $membershipService,
        EssayistZapClaimRepository $claimRepository,
        EventRepository $eventRepository,
        UserProfileService $userProfileService,
        LoggerInterface $logger,
    ): Response {
        /** @var User|null $viewer */
        $viewer = $this->getUser();
        if (!$viewer instanceof User) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'login_required']);
        }

        $viewerRoles = $viewer->getRoles();
        $allowed = in_array(RolesEnum::ESSAYIST_MEMBER->value, $viewerRoles, true)
            || in_array(RolesEnum::ESSAYIST_CANDIDATE->value, $viewerRoles, true)
            || in_array('ROLE_ADMIN', $viewerRoles, true);

        if (!$allowed) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'access_denied']);
        }

        $members = $userRepository->findByRoleWithQuery(RolesEnum::ESSAYIST_MEMBER->value, null, 500);

        // Resolve metadata + lud16 from Redis (falls back to the User's stored lud16).
        $hexPubkeys = [];
        $npubToHex  = [];
        foreach ($members as $member) {
            $npub = $member->getNpub();
            if ($npub && NostrKeyUtil::isNpub($npub)) {
                $hex                = NostrKeyUtil::npubToHex($npub);
                $hexPubkeys[]       = $hex;
                $npubToHex[$npub]   = $hex;
            }
        }
        $metadataMap = !empty($hexPubkeys) ? $redisCacheService->getMultipleMetadata($hexPubkeys) : [];

        // ── Resolve viewer's follow set (kind:3) for "follows first" sorting ──
        $followSet = [];
        try {
            $viewerHex = NostrKeyUtil::npubToHex($viewer->getUserIdentifier());
            $followsEvent = $eventRepository->findLatestByPubkeyAndKind($viewerHex, KindsEnum::FOLLOWS->value);
            if ($followsEvent !== null) {
                foreach ($followsEvent->getTags() as $tag) {
                    if (is_array($tag) && ($tag[0] ?? '') === 'p' && isset($tag[1]) && NostrKeyUtil::isHexPubkey($tag[1])) {
                        $followSet[$tag[1]] = true;
                    }
                }
            } else {
                // Fallback: ask the profile service (network).
                foreach ($userProfileService->getFollows($viewerHex) as $hex) {
                    if (NostrKeyUtil::isHexPubkey($hex)) {
                        $followSet[$hex] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            $logger->debug('Essayist members: could not resolve viewer follows', ['error' => $e->getMessage()]);
        }

        $rows = [];
        foreach ($members as $member) {
            $npub = (string) $member->getNpub();
            $hex  = $npubToHex[$npub] ?? null;
            $meta = $hex ? ($metadataMap[$hex] ?? null) : null;
            $std  = $meta ? (method_exists($meta, 'toStdClass') ? $meta->toStdClass() : $meta) : null;

            $lud16 = $member->getLud16() ?: ($std?->lud16 ?? null);
            if (is_array($lud16)) {
                $lud16 = $lud16[0] ?? null;
            }
            if (!$lud16) {
                // Without a Lightning address we can't generate an invoice.
                continue;
            }

            $memberRoles = $member->getRoles();
            $isFollowed  = $hex !== null && isset($followSet[$hex]);
            $isEarlyBird = in_array(RolesEnum::ESSAYIST_EARLY_BIRD->value, $memberRoles, true);

            // Three-bucket sort key: 0 = follow, 1 = early bird, 2 = other.
            $sortBucket = $isFollowed ? 0 : ($isEarlyBird ? 1 : 2);

            $rows[] = [
                'pubkey'      => $hex,
                'npub'        => $npub,
                'displayName' => $std?->display_name ?? $std?->name ?? $member->getDisplayName() ?? $member->getName() ?? $npub,
                'picture'     => $std?->picture ?? $member->getPicture() ?? '',
                'about'       => $std?->about ?? $member->getAbout() ?? '',
                'nip05'       => is_array($std?->nip05 ?? '') ? ($std->nip05[0] ?? '') : ($std?->nip05 ?? $member->getNip05() ?? ''),
                'lud16'       => $lud16,
                'isFollowed'  => $isFollowed,
                'isEarlyBird' => $isEarlyBird,
                '_sortBucket' => $sortBucket,
                '_rand'       => mt_rand(),
            ];
        }

        // Sort: follows first, then early birds, then others. Randomise within each bucket.
        usort($rows, static function (array $a, array $b): int {
            return ($a['_sortBucket'] <=> $b['_sortBucket'])
                ?: ($a['_rand'] <=> $b['_rand']);
        });

        $isMember  = in_array(RolesEnum::ESSAYIST_MEMBER->value, $viewerRoles, true);
        $isAdmin   = in_array('ROLE_ADMIN', $viewerRoles, true);

        // Effective expiry date that a payment made *now* would activate / confirm.
        // Used by the template to render a precise "valid through …" hint.
        $coverageThrough = \App\Service\Essayist\EssayistMembershipService::endOfNextMonth(new \DateTimeImmutable('now'));

        $claimsToAttest = [];
        if (NostrKeyUtil::isNpub((string) $viewer->getNpub())) {
            try {
                $viewerHex = NostrKeyUtil::npubToHex((string) $viewer->getNpub());
                $claimsToAttest = $claimRepository->findPendingForSponsorPubkey($viewerHex);
            } catch (\Throwable $e) {
                $logger->debug('Essayist members: failed loading pending attestation claims', ['error' => $e->getMessage()]);
            }
        }

        return $this->render('essayist/members.html.twig', [
            'members'         => $rows,
            'isLoggedIn'      => true,
            'isMember'        => $isMember,
            'isAdmin'         => $isAdmin,
            'minimumSats'     => $membershipService->getMinimumSats(),
            'coverageThrough' => $coverageThrough,
            'followCount'     => count($followSet),
            'claimsToAttest'  => $claimsToAttest,
        ]);
    }

    #[Route('/claims/{id}/attest', name: 'app_essayist_claim_attest', methods: ['POST'])]
    public function attestClaim(
        int $id,
        Request $request,
        EssayistZapClaimRepository $claimRepository,
        EssayistZapClaimService $claimService,
    ): Response {
        /** @var User|null $viewer */
        $viewer = $this->getUser();
        if (!$viewer instanceof User) {
            return $this->redirectToRoute('app_static_essayist', ['join_status' => 'login_required']);
        }

        if (!$this->isCsrfTokenValid('essayist_attest_claim_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('app_essayist_members');
        }

        $claim = $claimRepository->find($id);
        if ($claim === null) {
            $this->addFlash('error', 'Claim not found.');
            return $this->redirectToRoute('app_essayist_members');
        }

        $amount = $request->request->get('amount_sats');
        $amountSats = is_numeric($amount) ? (int) $amount : null;
        $eventId = trim((string) $request->request->get('attestation_event_id', ''));
        $note = trim((string) $request->request->get('note', ''));

        $ok = $claimService->attestByRecipient(
            $claim,
            $viewer,
            $amountSats,
            $eventId !== '' ? $eventId : null,
            $note !== '' ? $note : null,
        );

        if ($ok) {
            $this->addFlash('success', 'Payment confirmed. Membership has been extended for the payer.');
        } else {
            $this->addFlash('error', 'Could not confirm this claim. Ensure you are the zap recipient and provide an amount if missing.');
        }

        return $this->redirectToRoute('app_essayist_members');
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
     * Returns a Turbo Frame partial with the 2 latest Essayist relay articles.
     * Called periodically by the content--essayist-latest Stimulus controller.
     */
    #[Route('/sidebar/latest', name: 'app_essayist_sidebar_latest', methods: ['GET'])]
    public function sidebarLatest(): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if (!$user instanceof User) {
            return new Response('<turbo-frame id="essayist-sidebar-latest"></turbo-frame>', 200, ['Content-Type' => 'text/html']);
        }

        $roles   = $user->getRoles();
        $isAdmin = in_array('ROLE_ADMIN', $roles, true);

        if (!$isAdmin && !in_array(RolesEnum::ESSAYIST_MEMBER->value, $roles, true)) {
            return new Response('<turbo-frame id="essayist-sidebar-latest"></turbo-frame>', 200, ['Content-Type' => 'text/html']);
        }

        return $this->render('essayist/sidebar/_latest_articles.html.twig');
    }

    /**
     * Personalized home page for Essayist members.
     * Shows tabs: For You / Follows / Topics, plus a featured writers sidebar.
     *
     * Also activates (or re-activates) the live relay-feed subscription for
     * the strfry-essayist relay so the sidebar widget can receive Mercure
     * push updates instead of polling.
     */
    #[Route('/home', name: 'app_essayist_home', methods: ['GET'])]
    public function home(
        EssayistFeedService $feedService,
        RelayFeedBufferService $buffer,
        MessageBusInterface $bus,
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

        // ── Activate the live Mercure relay-feed for strfry-essayist ──
        // This starts (or keeps alive) a WebSocket subscription worker that
        // streams new kind:30023 events to the Mercure topic /relay-feed/{key},
        // which the sidebar widget subscribes to via EventSource.
        $relayUrl    = $feedService->getRelayUrl();
        $relayKey    = $buffer->makeKey($relayUrl);
        $mercureTopic = '/relay-feed/' . $relayKey;

        $buffer->storeRelayUrl($relayKey, $relayUrl);
        $buffer->markActive($relayKey);
        $bus->dispatch(new StartRelayFeedMessage($relayUrl, $relayKey));

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
            'mercure_topic'       => $mercureTopic,
        ]);
    }

    /**
     * Keepalive endpoint — renews the Redis active flag so the relay-feed worker
     * keeps re-dispatching while the essayist home page is open.
     * Called every ~5 minutes by the content--essayist-latest Stimulus controller.
     */
    #[Route('/home/keepalive', name: 'app_essayist_home_keepalive', methods: ['POST'])]
    public function homeKeepalive(
        EssayistFeedService $feedService,
        RelayFeedBufferService $buffer,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['ok' => false], 401);
        }

        $relayKey = $buffer->makeKey($feedService->getRelayUrl());
        $buffer->markActive($relayKey);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Turbo Frame tab endpoint for the Essayist personalized home page.
     */
    #[Route('/home/tab/{tab}', name: 'app_essayist_home_tab', requirements: ['tab' => 'foryou|follows|topics|activity'])]
    public function homeFeedTab(
        string $tab,
        EssayistFeedService $feedService,
        EssayistMemberActivityService $memberActivityService,
        EventRepository $eventRepository,
        UserProfileService $userProfileService,
        NostrClient $nostrClient,
        RedisCacheService $redisCacheService,
        UserMuteListService $userMuteListService,
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

        // Resolve the user's mute list once; applies to all tabs.
        $mutedPubkeys = [];
        if ($pubkeyHex) {
            try {
                $mutedPubkeys = $userMuteListService->getMutedPubkeys($pubkeyHex);
            } catch (\Throwable) {
                // Non-critical — proceed without muting
            }
        }

        return match ($tab) {
            'follows' => $this->essayistFollowsTab($feedService, $eventRepository, $userProfileService, $redisCacheService, $logger, $pubkeyHex, $mutedPubkeys),
            'topics'  => $this->essayistTopicsTab($feedService, $nostrClient, $redisCacheService, $logger, $pubkeyHex, $mutedPubkeys),
            'activity' => $this->essayistActivityTab($memberActivityService),
            default   => $this->essayistForYouTab($feedService, $eventRepository, $userProfileService, $nostrClient, $redisCacheService, $logger, $pubkeyHex, $mutedPubkeys),
        };
    }

    private function essayistActivityTab(EssayistMemberActivityService $memberActivityService): Response
    {
        $activity = $memberActivityService->getRecentActivity(60);

        return $this->render('essayist/tabs/_activity.html.twig', [
            'isLoggedIn' => true,
            'activity' => $activity,
        ]);
    }

    // ── Private tab helpers ────────────────────────────────────────────────

    private function essayistFollowsTab(
        EssayistFeedService $feedService,
        EventRepository $eventRepository,
        UserProfileService $userProfileService,
        RedisCacheService $redisCacheService,
        LoggerInterface $logger,
        ?string $pubkeyHex,
        array $mutedPubkeys = [],
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

        if (!empty($mutedPubkeys)) {
            $articles = array_values(array_filter($articles, fn (object $c): bool => !in_array($c->pubkey, $mutedPubkeys, true)));
        }

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
        array $mutedPubkeys = [],
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

        if (!empty($mutedPubkeys)) {
            $articles = array_values(array_filter($articles, fn (object $c): bool => !in_array($c->pubkey, $mutedPubkeys, true)));
        }

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
        array $mutedPubkeys = [],
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

        // ── 3. Articles with recent comments from the Essayist relay ──
        // coordinate → latest_comment_at Unix timestamp (for sorting discussed articles to top)
        $discussedOrder = [];
        try {
            $since       = time() - 7 * 24 * 3600; // last 7 days
            $coordinates = $eventRepository->findRecentlyCommentedLongformCoordinates($since, 50);
            if (!empty($coordinates)) {
                // Extract d-tags and build expected "pubkey:d-tag" coord set.
                $dTags              = [];
                $allowedCoordinates = [];
                foreach ($coordinates as $fullCoord => $latestCommentAt) {
                    // fullCoord format: 30023:<pubkey>:<d-tag>
                    $parts = explode(':', $fullCoord, 3);
                    if (count($parts) === 3) {
                        $shortCoord           = $parts[1] . ':' . $parts[2]; // pubkey:d-tag
                        $dTags[]              = $parts[2];
                        $allowedCoordinates[] = $shortCoord;
                        $discussedOrder[$shortCoord] = $latestCommentAt;
                    }
                }
                $commentedArticles = $feedService->fetchByDTags($dTags, $allowedCoordinates, 50);
                foreach ($commentedArticles as $card) {
                    $coord = $card->pubkey . ':' . $card->slug;
                    if (!isset($mergedArticles[$coord])) {
                        $mergedArticles[$coord] = $card;
                        $sourceLabels[$coord]   = [];
                    }
                    if (!in_array('discussed', $sourceLabels[$coord], true)) {
                        $sourceLabels[$coord][] = 'discussed';
                    }
                }
            }
        } catch (\Throwable $e) {
            $logger->error('EssayistHome for-you: failed to load discussed articles', ['error' => $e->getMessage()]);
        }

        // ── 4. Sort merged articles ──
        // Remove muted authors across all sources.
        if (!empty($mutedPubkeys)) {
            foreach (array_keys($mergedArticles) as $coord) {
                if (in_array($mergedArticles[$coord]->pubkey, $mutedPubkeys, true)) {
                    unset($mergedArticles[$coord], $sourceLabels[$coord], $discussedOrder[$coord]);
                }
            }
        }

        // Discussed articles are sorted by latest comment time (most recently active first);
        // all others fall back to article createdAt, consistent with the global home feed.
        $articlesArray = array_values($mergedArticles);
        usort($articlesArray, function (object $a, object $b) use ($discussedOrder): int {
            $coordA = $a->pubkey . ':' . $a->slug;
            $coordB = $b->pubkey . ':' . $b->slug;
            $timeA  = $discussedOrder[$coordA] ?? $a->createdAt->getTimestamp();
            $timeB  = $discussedOrder[$coordB] ?? $b->createdAt->getTimestamp();
            return $timeB <=> $timeA;
        });
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

