<?php

declare(strict_types=1);

namespace App\ChatBundle\Controller;

use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Repository\ChatGroupMembershipRepository;
use App\ChatBundle\Repository\ChatGroupRepository;
use App\ChatBundle\Service\ChatAuthorizationChecker;
use App\ChatBundle\Service\ChatCommunityResolver;
use App\ChatBundle\Service\ChatGroupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ChatManageController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityResolver $communityResolver,
        private readonly ChatGroupRepository $groupRepo,
        private readonly ChatGroupMembershipRepository $membershipRepo,
        private readonly ChatAuthorizationChecker $authChecker,
        private readonly ChatGroupService $groupService,
    ) {}

    public function group(string $slug): Response
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getChatUser();
        $group = $this->groupRepo->findBySlugAndCommunity($slug, $community);

        if ($group === null || !$this->authChecker->isGroupAdmin($user, $group)) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('@Chat/manage/groups.html.twig', [
            'community' => $community,
            'group' => $group,
        ]);
    }

    public function members(string $slug, Request $request): Response
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getChatUser();
        $group = $this->groupRepo->findBySlugAndCommunity($slug, $community);

        if ($group === null || !$this->authChecker->isGroupAdmin($user, $group)) {
            throw $this->createAccessDeniedException();
        }

        $members = $this->membershipRepo->findByGroup($group);

        return $this->render('@Chat/manage/members.html.twig', [
            'community' => $community,
            'group' => $group,
            'members' => $members,
        ]);
    }

    public function invites(): Response
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getChatUser();

        if (!$this->authChecker->isCommunityGuardianOrAdmin($user, $community)) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('@Chat/manage/invites.html.twig', [
            'community' => $community,
        ]);
    }

    private function getChatUser(): ChatUser
    {
        $user = $this->getUser();
        if (!$user instanceof ChatUser) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }
}

