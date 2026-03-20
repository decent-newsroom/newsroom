<?php

declare(strict_types=1);

namespace App\ChatBundle\Controller;

use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Repository\ChatGroupMembershipRepository;
use App\ChatBundle\Repository\ChatGroupRepository;
use App\ChatBundle\Service\ChatCommunityResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ChatSettingsController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityResolver $communityResolver,
        private readonly ChatGroupRepository $groupRepo,
        private readonly ChatGroupMembershipRepository $membershipRepo,
    ) {}

    public function index(): Response
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getChatUser();

        $memberships = $this->membershipRepo->findByUser($user);

        return $this->render('@Chat/settings.html.twig', [
            'community' => $community,
            'memberships' => $memberships,
        ]);
    }

    /**
     * Toggle muted_notifications for a group membership.
     */
    public function toggleMuteNotifications(string $slug): JsonResponse
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getChatUser();

        $group = $this->groupRepo->findBySlugAndCommunity($slug, $community);
        if ($group === null) {
            return new JsonResponse(['error' => 'Group not found'], Response::HTTP_NOT_FOUND);
        }

        $membership = $this->membershipRepo->findByUserAndGroup($user, $group);
        if ($membership === null) {
            return new JsonResponse(['error' => 'Not a member'], Response::HTTP_FORBIDDEN);
        }

        $membership->setMutedNotifications(!$membership->isMutedNotifications());
        $this->membershipRepo->save($membership);

        return new JsonResponse([
            'muted' => $membership->isMutedNotifications(),
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
