<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

use App\ChatBundle\Entity\ChatCommunity;
use App\ChatBundle\Entity\ChatGroup;
use App\ChatBundle\Entity\ChatGroupMembership;
use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Enum\ChatGroupStatus;
use App\ChatBundle\Repository\ChatGroupMembershipRepository;
use App\ChatBundle\Repository\ChatGroupRepository;
use Doctrine\ORM\EntityManagerInterface;

class ChatGroupService
{
    public function __construct(
        private readonly ChatGroupRepository $groupRepo,
        private readonly ChatGroupMembershipRepository $membershipRepo,
        private readonly ChatEventSigner $signer,
        private readonly ChatRelayClient $relayClient,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Create a group and publish its kind 40 channel create event.
     * The creating user (admin) signs the channel create event.
     */
    public function createGroup(ChatCommunity $community, string $name, string $slug, ChatUser $creator): ChatGroup
    {
        $group = new ChatGroup();
        $group->setCommunity($community);
        $group->setName($name);
        $group->setSlug($slug);

        // Sign kind 40 channel create event
        $channelMeta = json_encode(['name' => $name, 'about' => '', 'picture' => '']);
        $signedJson = $this->signer->signForUser($creator, 40, [], $channelMeta);
        $signedEvent = json_decode($signedJson);

        $group->setChannelEventId($signedEvent->id ?? '');
        $this->em->persist($group);

        // Publish to chat relay
        $this->relayClient->publish($signedJson, $community);

        // Add creator as group admin
        $membership = new ChatGroupMembership();
        $membership->setUser($creator);
        $membership->setGroup($group);
        $membership->setRole('admin');
        $this->em->persist($membership);

        $this->em->flush();

        return $group;
    }

    public function archiveGroup(ChatGroup $group): void
    {
        $group->setStatus(ChatGroupStatus::ARCHIVED);
        $this->em->flush();
    }

    public function addMember(ChatGroup $group, ChatUser $user, string $role = 'member'): void
    {
        if ($this->membershipRepo->isMember($user, $group)) {
            return;
        }

        $membership = new ChatGroupMembership();
        $membership->setUser($user);
        $membership->setGroup($group);
        $membership->setRole($role);
        $this->em->persist($membership);
        $this->em->flush();
    }

    public function removeMember(ChatGroup $group, ChatUser $user): void
    {
        $membership = $this->membershipRepo->findByUserAndGroup($user, $group);
        if ($membership !== null) {
            $this->membershipRepo->remove($membership);
        }
    }
}

