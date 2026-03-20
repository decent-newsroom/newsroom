<?php

declare(strict_types=1);

namespace App\Controller\Administration\Chat;

use App\ChatBundle\Enum\ChatInviteType;
use App\ChatBundle\Repository\ChatCommunityRepository;
use App\ChatBundle\Repository\ChatInviteRepository;
use App\ChatBundle\Repository\ChatUserRepository;
use App\ChatBundle\Service\ChatInviteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/chat/communities/{communityId}/invites')]
#[IsGranted('ROLE_ADMIN')]
class ChatInviteAdminController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityRepository $communityRepo,
        private readonly ChatInviteRepository $inviteRepo,
        private readonly ChatUserRepository $userRepo,
        private readonly ChatInviteService $inviteService,
    ) {}

    #[Route('', name: 'admin_chat_invites')]
    public function index(int $communityId): Response
    {
        $community = $this->communityRepo->find($communityId) ?? throw $this->createNotFoundException();
        $invites = $this->inviteRepo->findBy(['community' => $community], ['createdAt' => 'DESC']);

        return $this->render('admin/chat/invites/index.html.twig', [
            'community' => $community,
            'invites' => $invites,
        ]);
    }

    #[Route('/generate', name: 'admin_chat_invite_generate', methods: ['POST'])]
    public function generate(int $communityId, Request $request): Response
    {
        $community = $this->communityRepo->find($communityId) ?? throw $this->createNotFoundException();

        $users = $this->userRepo->findByCommunity($community);
        $createdBy = $users[0] ?? null;
        if ($createdBy === null) {
            $this->addFlash('error', 'Create at least one user first.');
            return $this->redirectToRoute('admin_chat_invites', ['communityId' => $communityId]);
        }

        $roleToGrant = $request->request->get('role', 'user');
        $maxUses = $request->request->getInt('max_uses', 0) ?: null;

        $plaintextToken = $this->inviteService->generateInvite(
            $community,
            ChatInviteType::ACTIVATION,
            $createdBy,
            $roleToGrant,
            null,
            $maxUses,
        );

        $inviteUrl = sprintf('https://%s.%s/activate/%s',
            $community->getSubdomain(),
            $request->getHost(),
            $plaintextToken
        );

        $this->addFlash('success', 'Invite generated: ' . $inviteUrl);
        return $this->redirectToRoute('admin_chat_invites', ['communityId' => $communityId]);
    }

    #[Route('/{inviteId}/revoke', name: 'admin_chat_invite_revoke', methods: ['POST'])]
    public function revoke(int $communityId, int $inviteId): Response
    {
        $invite = $this->inviteRepo->find($inviteId) ?? throw $this->createNotFoundException();
        $this->inviteService->revokeInvite($invite);

        $this->addFlash('success', 'Invite revoked.');
        return $this->redirectToRoute('admin_chat_invites', ['communityId' => $communityId]);
    }
}

