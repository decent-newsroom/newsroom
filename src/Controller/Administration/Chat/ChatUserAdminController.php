<?php

declare(strict_types=1);

namespace App\Controller\Administration\Chat;

use App\ChatBundle\Enum\ChatRole;
use App\ChatBundle\Repository\ChatCommunityRepository;
use App\ChatBundle\Repository\ChatUserRepository;
use App\ChatBundle\Service\ChatUserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/chat/communities/{communityId}/users')]
#[IsGranted('ROLE_ADMIN')]
class ChatUserAdminController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityRepository $communityRepo,
        private readonly ChatUserRepository $userRepo,
        private readonly ChatUserService $userService,
    ) {}

    #[Route('', name: 'admin_chat_users')]
    public function index(int $communityId): Response
    {
        $community = $this->communityRepo->find($communityId) ?? throw $this->createNotFoundException();
        $users = $this->userRepo->findByCommunity($community);

        return $this->render('admin/chat/users/index.html.twig', [
            'community' => $community,
            'users' => $users,
        ]);
    }

    #[Route('/create', name: 'admin_chat_user_create', methods: ['POST'])]
    public function create(int $communityId, Request $request): Response
    {
        $community = $this->communityRepo->find($communityId) ?? throw $this->createNotFoundException();

        $displayName = $request->request->get('display_name', 'New User');
        $roleValue = $request->request->get('role', 'user');
        $role = ChatRole::from($roleValue);

        $this->userService->createUser($community, $displayName, $role);

        $this->addFlash('success', 'User created.');
        return $this->redirectToRoute('admin_chat_users', ['communityId' => $communityId]);
    }

    #[Route('/{userId}/suspend', name: 'admin_chat_user_suspend', methods: ['POST'])]
    public function suspend(int $communityId, int $userId): Response
    {
        $user = $this->userRepo->find($userId) ?? throw $this->createNotFoundException();
        $this->userService->suspendUser($user);

        $this->addFlash('success', 'User suspended.');
        return $this->redirectToRoute('admin_chat_users', ['communityId' => $communityId]);
    }

    #[Route('/{userId}/activate', name: 'admin_chat_user_activate', methods: ['POST'])]
    public function activate(int $communityId, int $userId): Response
    {
        $user = $this->userRepo->find($userId) ?? throw $this->createNotFoundException();
        $this->userService->activateUser($user);

        $this->addFlash('success', 'User activated.');
        return $this->redirectToRoute('admin_chat_users', ['communityId' => $communityId]);
    }
}

