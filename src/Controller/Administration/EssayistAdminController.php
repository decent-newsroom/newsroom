<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\User;
use App\Enum\RolesEnum;
use App\Repository\ArticleRepository;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
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
    #[Route('/admin/essayist', name: 'admin_essayist_index', methods: ['GET'])]
    public function index(
        UserEntityRepository $userRepository,
        ArticleRepository $articleRepository,
        RedisCacheService $redisCacheService,
    ): Response {
        $candidates = $userRepository->findByRoleWithQuery(RolesEnum::ESSAYIST_CANDIDATE->value);
        $authors    = $userRepository->findByRoleWithQuery(RolesEnum::ESSAYIST_AUTHOR->value);
        $supporters = $userRepository->findByRoleWithQuery(RolesEnum::ESSAYIST_SUPPORTER->value);

        $candidates = $this->enrichWithMetadataAndArticleCount($candidates, $redisCacheService, $articleRepository);
        $authors    = $this->enrichWithMetadata($authors, $redisCacheService);
        $supporters = $this->enrichWithMetadata($supporters, $redisCacheService);

        return $this->render('admin/essayist.html.twig', [
            'candidates' => $candidates,
            'authors'    => $authors,
            'supporters' => $supporters,
        ]);
    }

    /** Approve a candidate: remove CANDIDATE, add AUTHOR */
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
        $user->addRole(RolesEnum::ESSAYIST_AUTHOR->value);
        $em->flush();

        $this->addFlash('success', sprintf('Approved %s as Essayist author.', $user->getNpub()));

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

    /** Revoke author: remove AUTHOR role entirely */
    #[Route('/admin/essayist/authors/{id}/revoke', name: 'admin_essayist_revoke_author', methods: ['POST'])]
    public function revokeAuthor(
        int $id,
        UserEntityRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $user = $userRepository->find($id);
        if (!$user) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('admin_essayist_index');
        }

        $user->removeRole(RolesEnum::ESSAYIST_AUTHOR->value);
        $em->flush();

        $this->addFlash('success', sprintf('Revoked author access for %s.', $user->getNpub()));

        return $this->redirectToRoute('admin_essayist_index');
    }

    /** Downgrade author back to candidate */
    #[Route('/admin/essayist/authors/{id}/downgrade', name: 'admin_essayist_downgrade_author', methods: ['POST'])]
    public function downgradeAuthor(
        int $id,
        UserEntityRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $user = $userRepository->find($id);
        if (!$user) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('admin_essayist_index');
        }

        $user->removeRole(RolesEnum::ESSAYIST_AUTHOR->value);
        $user->addRole(RolesEnum::ESSAYIST_CANDIDATE->value);
        $em->flush();

        $this->addFlash('success', sprintf('Downgraded %s back to candidate.', $user->getNpub()));

        return $this->redirectToRoute('admin_essayist_index');
    }

    /** Manually grant supporter role by npub */
    #[Route('/admin/essayist/supporters/grant', name: 'admin_essayist_grant_supporter', methods: ['POST'])]
    public function grantSupporter(
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
            $user->setRoles([RolesEnum::ESSAYIST_SUPPORTER->value]);
            $em->persist($user);
            $this->addFlash('success', sprintf('Created user and granted supporter access to %s.', $npub));
        } else {
            if (in_array(RolesEnum::ESSAYIST_SUPPORTER->value, $user->getRoles(), true)) {
                $this->addFlash('warning', 'User already has supporter access.');
                return $this->redirectToRoute('admin_essayist_index');
            }
            $user->addRole(RolesEnum::ESSAYIST_SUPPORTER->value);
            $this->addFlash('success', sprintf('Granted supporter access to %s.', $npub));
        }

        $em->flush();

        return $this->redirectToRoute('admin_essayist_index');
    }

    /** Revoke supporter role */
    #[Route('/admin/essayist/supporters/{id}/revoke', name: 'admin_essayist_revoke_supporter', methods: ['POST'])]
    public function revokeSupporter(
        int $id,
        UserEntityRepository $userRepository,
        EntityManagerInterface $em,
    ): Response {
        $user = $userRepository->find($id);
        if (!$user) {
            $this->addFlash('error', 'User not found.');
            return $this->redirectToRoute('admin_essayist_index');
        }

        $user->removeRole(RolesEnum::ESSAYIST_SUPPORTER->value);
        $em->flush();

        $this->addFlash('success', sprintf('Revoked supporter access for %s.', $user->getNpub()));

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

