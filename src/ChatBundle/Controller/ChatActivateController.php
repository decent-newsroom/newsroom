<?php

declare(strict_types=1);

namespace App\ChatBundle\Controller;

use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Enum\ChatInviteType;
use App\ChatBundle\Enum\ChatUserStatus;
use App\ChatBundle\Service\ChatCommunityResolver;
use App\ChatBundle\Service\ChatGroupService;
use App\ChatBundle\Service\ChatInviteService;
use App\ChatBundle\Service\ChatSessionManager;
use App\ChatBundle\Service\ChatUserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ChatActivateController extends AbstractController
{
    public function __construct(
        private readonly ChatInviteService $inviteService,
        private readonly ChatUserService $userService,
        private readonly ChatGroupService $groupService,
        private readonly ChatSessionManager $sessionManager,
        private readonly ChatCommunityResolver $communityResolver,
    ) {}

    public function activate(string $token, Request $request): Response
    {
        $community = $this->communityResolver->resolve();
        if ($community === null) {
            throw $this->createNotFoundException();
        }

        $invite = $this->inviteService->validateToken($token);
        if ($invite === null) {
            return $this->render('@Chat/activate.html.twig', [
                'error' => 'This invite link is invalid, expired, or has been revoked.',
                'community' => $community,
            ]);
        }

        // Check invite belongs to this community
        if ($invite->getCommunity()->getId() !== $community->getId()) {
            return $this->render('@Chat/activate.html.twig', [
                'error' => 'This invite link is not valid for this community.',
                'community' => $community,
            ]);
        }

        // Create user via the invite
        $user = $this->userService->createUser(
            $community,
            'Member ' . substr(bin2hex(random_bytes(3)), 0, 6),
            \App\ChatBundle\Enum\ChatRole::from($invite->getRoleToGrant()),
        );

        $this->userService->activateUser($user);
        $this->inviteService->redeemInvite($invite);

        // If group-scoped invite, add to group
        if ($invite->getGroup() !== null) {
            $this->groupService->addMember($invite->getGroup(), $user);
        }

        // Create session
        $sessionToken = $this->sessionManager->createSession($user, $community);

        $response = $this->redirect('/groups');
        $this->sessionManager->setCookie($response, $sessionToken, $community->getSubdomain());

        return $response;
    }
}

