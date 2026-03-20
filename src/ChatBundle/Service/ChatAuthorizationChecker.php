<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

use App\ChatBundle\Entity\ChatCommunity;
use App\ChatBundle\Entity\ChatCommunityMembership;
use App\ChatBundle\Entity\ChatGroup;
use App\ChatBundle\Entity\ChatGroupMembership;
use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Enum\ChatRole;
use App\ChatBundle\Repository\ChatCommunityMembershipRepository;
use App\ChatBundle\Repository\ChatGroupMembershipRepository;
use Psr\Log\LoggerInterface;

/**
 * Fail-closed authorization checks for chat operations.
 * Every controller action must call these before proceeding.
 */
class ChatAuthorizationChecker
{
    public function __construct(
        private readonly ChatCommunityMembershipRepository $communityMembershipRepo,
        private readonly ChatGroupMembershipRepository $groupMembershipRepo,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function canAccessCommunity(ChatUser $user, ChatCommunity $community): bool
    {
        if (!$user->isActive()) {
            $this->logDenied('canAccessCommunity', $user, 'user not active');
            return false;
        }
        if (!$community->isActive()) {
            $this->logDenied('canAccessCommunity', $user, 'community suspended');
            return false;
        }
        $membership = $this->communityMembershipRepo->findByUserAndCommunity($user, $community);
        if ($membership === null) {
            $this->logDenied('canAccessCommunity', $user, 'no community membership');
            return false;
        }
        return true;
    }

    public function canAccessGroup(ChatUser $user, ChatGroup $group): bool
    {
        if (!$this->canAccessCommunity($user, $group->getCommunity())) {
            return false;
        }
        if (!$group->isActive()) {
            $this->logDenied('canAccessGroup', $user, 'group archived');
            return false;
        }
        if (!$this->groupMembershipRepo->isMember($user, $group)) {
            $this->logDenied('canAccessGroup', $user, 'not a member of group');
            return false;
        }
        return true;
    }

    public function canSendMessage(ChatUser $user, ChatGroup $group): bool
    {
        return $this->canAccessGroup($user, $group);
    }

    public function isGroupAdmin(ChatUser $user, ChatGroup $group): bool
    {
        $membership = $this->groupMembershipRepo->findByUserAndGroup($user, $group);
        return $membership !== null && $membership->isAdmin();
    }

    public function isCommunityAdmin(ChatUser $user, ChatCommunity $community): bool
    {
        $membership = $this->communityMembershipRepo->findByUserAndCommunity($user, $community);
        return $membership !== null && $membership->getRole() === ChatRole::ADMIN;
    }

    public function isCommunityGuardianOrAdmin(ChatUser $user, ChatCommunity $community): bool
    {
        $membership = $this->communityMembershipRepo->findByUserAndCommunity($user, $community);
        if ($membership === null) {
            return false;
        }
        return in_array($membership->getRole(), [ChatRole::ADMIN, ChatRole::GUARDIAN], true);
    }

    private function logDenied(string $check, ChatUser $user, string $reason): void
    {
        $this->logger?->warning('Chat authorization denied', [
            'check' => $check,
            'pubkey' => $user->getPubkey(),
            'reason' => $reason,
        ]);
    }
}

