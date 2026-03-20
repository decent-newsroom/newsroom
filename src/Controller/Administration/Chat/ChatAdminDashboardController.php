<?php

declare(strict_types=1);

namespace App\Controller\Administration\Chat;

use App\ChatBundle\Repository\ChatCommunityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/chat')]
#[IsGranted('ROLE_ADMIN')]
class ChatAdminDashboardController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityRepository $communityRepo,
    ) {}

    #[Route('', name: 'admin_chat_dashboard')]
    public function index(): Response
    {
        $communities = $this->communityRepo->findAll();

        return $this->render('admin/chat/dashboard.html.twig', [
            'communities' => $communities,
        ]);
    }
}

