<?php

declare(strict_types=1);

namespace App\Controller\Administration\Chat;

use App\ChatBundle\Repository\ChatCommunityRepository;
use App\ChatBundle\Repository\ChatSessionRepository;
use App\ChatBundle\Service\ChatSessionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/chat/communities/{communityId}/sessions')]
#[IsGranted('ROLE_ADMIN')]
class ChatSessionAdminController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityRepository $communityRepo,
        private readonly ChatSessionRepository $sessionRepo,
        private readonly ChatSessionManager $sessionManager,
    ) {}

    #[Route('', name: 'admin_chat_sessions')]
    public function index(int $communityId): Response
    {
        $community = $this->communityRepo->find($communityId) ?? throw $this->createNotFoundException();
        $sessions = $this->sessionRepo->findBy(['community' => $community], ['lastSeenAt' => 'DESC']);

        return $this->render('admin/chat/sessions/index.html.twig', [
            'community' => $community,
            'sessions' => $sessions,
        ]);
    }

    #[Route('/{sessionId}/revoke', name: 'admin_chat_session_revoke', methods: ['POST'])]
    public function revoke(int $communityId, int $sessionId): Response
    {
        $session = $this->sessionRepo->find($sessionId) ?? throw $this->createNotFoundException();
        $this->sessionManager->revokeSession($session);

        $this->addFlash('success', 'Session revoked.');
        return $this->redirectToRoute('admin_chat_sessions', ['communityId' => $communityId]);
    }
}

