<?php

declare(strict_types=1);

namespace App\ChatBundle\Controller;

use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Repository\ChatGroupMembershipRepository;
use App\ChatBundle\Repository\ChatUserRepository;
use App\ChatBundle\Service\ChatCommunityResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class ChatContactsController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityResolver $communityResolver,
        private readonly ChatGroupMembershipRepository $groupMembershipRepo,
        private readonly ChatUserRepository $userRepo,
    ) {}

    public function index(): Response
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getUser();
        if (!$user instanceof ChatUser) {
            throw $this->createAccessDeniedException();
        }

        $users = $this->userRepo->findByCommunity($community);

        return $this->render('@Chat/contacts/index.html.twig', [
            'community' => $community,
            'users' => $users,
            'currentUser' => $user,
        ]);
    }
}

