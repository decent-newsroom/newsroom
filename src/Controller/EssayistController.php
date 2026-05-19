<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\RolesEnum;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Essayist\EssayistFeedService;
use App\Service\Essayist\EssayistMembershipCacheService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
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
}

