<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\User;
use App\Enum\RolesEnum;
use App\Repository\ArticleRepository;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Essayist\EssayistMembershipCacheService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class EssayistAdminController extends AbstractController
{
    public function __construct(
        private readonly EssayistMembershipCacheService $membershipCache,
    ) {
    }

    #[Route('/admin/essayist', name: 'admin_essayist_index', methods: ['GET'])]
    public function index(
        UserEntityRepository $userRepository,
        ArticleRepository $articleRepository,
        RedisCacheService $redisCacheService,
    ): Response {
        $members    = $userRepository->findByRoleWithQuery(RolesEnum::ESSAYIST_MEMBER->value, null, 500);
        $candidates = $userRepository->findByRoleWithQuery(RolesEnum::ESSAYIST_CANDIDATE->value, null, 500);

        $members    = $this->enrichWithMetadata($members, $redisCacheService);
        $candidates = $this->enrichWithMetadataAndArticleCount($candidates, $redisCacheService, $articleRepository);

        return $this->render('admin/essayist.html.twig', [
            'members'    => $members,
            'candidates' => $candidates,
        ]);
    }

    /** Manually grant member role by npub */
    #[Route('/admin/essayist/members/grant', name: 'admin_essayist_grant_member', methods: ['POST'])]
    public function grantMember(
        Request $request,
        UserEntityRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $npub = trim((string) $request->request->get('npub', ''));

        if (!$npub || !str_starts_with($npub, 'npub1')) {
            $this->addFlash('error', 'Invalid npub format. Must start with npub1.');
            return $this->redirectToRoute('admin_essayist_index');
        }

        $user = $userRepository->findOneBy(['npub' => $npub]);
        if (!$user) {
            $user = new User();
            $user->setNpub($npub);
            $user->setRoles([RolesEnum::ESSAYIST_MEMBER->value]);
            $em->persist($user);
            $this->addFlash('success', sprintf('Created user and granted membership to %s.', $npub));
        } else {
            if (in_array(RolesEnum::ESSAYIST_MEMBER->value, $user->getRoles(), true)) {
                $this->addFlash('warning', 'User already has membership.');
                return $this->redirectToRoute('admin_essayist_index');
            }
            $user->addRole(RolesEnum::ESSAYIST_MEMBER->value);
            $this->addFlash('success', sprintf('Granted membership to %s.', $npub));
        }

        $em->flush();

        $this->membershipCache->markApproved($npub);

        return $this->redirectToRoute('admin_essayist_index');
    }

    /** Revoke member role */
    #[Route('/admin/essayist/members/{id}/revoke', name: 'admin_essayist_revoke_member', methods: ['POST'])]
    public function revokeMember(
        int $id,
        UserEntityRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $user = $userRepository->find($id);
        if (!$user) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('admin_essayist_index');
        }

        $user->removeRole(RolesEnum::ESSAYIST_MEMBER->value);
        $em->flush();

        $this->membershipCache->markRevoked((string) $user->getNpub());

        $this->addFlash('success', sprintf('Revoked membership for %s.', $user->getNpub()));

        return $this->redirectToRoute('admin_essayist_index');
    }

    /** Approve a candidate: remove CANDIDATE, grant MEMBER */
    #[Route('/admin/essayist/candidates/{id}/approve', name: 'admin_essayist_approve', methods: ['POST'])]
    public function approveCandidate(
        int $id,
        UserEntityRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $user = $userRepository->find($id);
        if (!$user) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('admin_essayist_index');
        }

        $user->removeRole(RolesEnum::ESSAYIST_CANDIDATE->value);
        $user->addRole(RolesEnum::ESSAYIST_MEMBER->value);
        $em->flush();

        $this->membershipCache->markApproved((string) $user->getNpub());

        $this->addFlash('success', sprintf('Approved %s — membership granted.', $user->getNpub()));

        return $this->redirectToRoute('admin_essayist_index');
    }

    /** Reject a candidate: remove CANDIDATE role */
    #[Route('/admin/essayist/candidates/{id}/reject', name: 'admin_essayist_reject', methods: ['POST'])]
    public function rejectCandidate(
        int $id,
        UserEntityRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $user = $userRepository->find($id);
        if (!$user) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('admin_essayist_index');
        }

        $user->removeRole(RolesEnum::ESSAYIST_CANDIDATE->value);
        $em->flush();

        $this->addFlash('success', sprintf('Rejected candidate %s.', $user->getNpub()));

        return $this->redirectToRoute('admin_essayist_index');
    }

    /**
     * Enrich users with Nostr metadata and article count.
     *
     * @param User[] $users
     * @return array<int, array{user: User, npub: string, metadata: object|null, articleCount: int}>
     */
    private function enrichWithMetadataAndArticleCount(
        array $users,
        RedisCacheService $redisCacheService,
        ArticleRepository $articleRepository,
    ): array {
        if (empty($users)) {
            return [];
        }

        $hexPubkeys = [];
        $npubToHex  = [];
        foreach ($users as $user) {
            $npub = $user->getNpub();
            if ($npub && NostrKeyUtil::isNpub($npub)) {
                $hex               = NostrKeyUtil::npubToHex($npub);
                $hexPubkeys[]      = $hex;
                $npubToHex[$npub]  = $hex;
            }
        }

        $metadataMap = !empty($hexPubkeys) ? $redisCacheService->getMultipleMetadata($hexPubkeys) : [];

        // Count articles for each candidate in a single query
        $articleCounts = !empty($hexPubkeys) ? $articleRepository->countArticlesByPubkeys($hexPubkeys) : [];

        $result = [];
        foreach ($users as $user) {
            $npub         = $user->getNpub();
            $hex          = $npubToHex[$npub] ?? null;
            $metadata     = $hex ? ($metadataMap[$hex] ?? null) : null;
            $articleCount = $hex ? ($articleCounts[$hex] ?? 0) : 0;
            $result[]     = [
                'user'         => $user,
                'npub'         => $npub,
                'metadata'     => $metadata ? $metadata->toStdClass() : null,
                'articleCount' => $articleCount,
            ];
        }

        return $result;
    }

    /**
     * Enrich users with Nostr metadata only.
     *
     * @param User[] $users
     * @return array<int, array{user: User, npub: string, metadata: object|null}>
     */
    private function enrichWithMetadata(array $users, RedisCacheService $redisCacheService): array
    {
        if (empty($users)) {
            return [];
        }

        $hexPubkeys = [];
        $npubToHex  = [];
        foreach ($users as $user) {
            $npub = $user->getNpub();
            if ($npub && NostrKeyUtil::isNpub($npub)) {
                $hex              = NostrKeyUtil::npubToHex($npub);
                $hexPubkeys[]     = $hex;
                $npubToHex[$npub] = $hex;
            }
        }

        $metadataMap = !empty($hexPubkeys) ? $redisCacheService->getMultipleMetadata($hexPubkeys) : [];

        $result = [];
        foreach ($users as $user) {
            $npub     = $user->getNpub();
            $hex      = $npubToHex[$npub] ?? null;
            $metadata = $hex ? ($metadataMap[$hex] ?? null) : null;
            $result[] = [
                'user'     => $user,
                'npub'     => $npub,
                'metadata' => $metadata ? $metadata->toStdClass() : null,
            ];
        }

        return $result;
    }
}

