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
     * For custodial users: signs server-side.
     * For self-sovereign users: returns unsigned event for client-side signing.
     *
     * @return ChatGroup|array ChatGroup (custodial) or ['group' => ChatGroup, 'unsignedEvent' => array] (self-sovereign)
     */
    public function createGroup(ChatCommunity $community, string $name, string $slug, ChatUser $creator)
    {
        $group = new ChatGroup();
        $group->setCommunity($community);
        $group->setName($name);
        $group->setSlug($slug);

        $channelMeta = json_encode(['name' => $name, 'about' => '', 'picture' => '']);

        if ($creator->isCustodial()) {
            // Server-side signing for custodial users
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
        } else {
            // For self-sovereign users: prepare unsigned event for client-side signing
            $this->em->persist($group);
            $this->em->flush();

            // Add creator as group admin (without flushing yet)
            $membership = new ChatGroupMembership();
            $membership->setUser($creator);
            $membership->setGroup($group);
            $membership->setRole('admin');
            $this->em->persist($membership);
            $this->em->flush();

            // Return unsigned event for client signing
            $unsignedEvent = [
                'kind' => 40,
                'pubkey' => $creator->getPubkey(),
                'created_at' => time(),
                'tags' => [],
                'content' => $channelMeta,
            ];

            return [
                'group' => $group,
                'unsignedEvent' => $unsignedEvent,
            ];
        }
    }

    /**
     * Publish a pre-signed kind-40 channel create event from a self-sovereign user.
     * Validates the event and sets it on the group.
     */
    public function publishSignedChannelEvent(ChatGroup $group, ChatUser $creator, string $signedEventJson): void
    {
        if ($creator->isCustodial()) {
            throw new \RuntimeException('publishSignedChannelEvent() called for a custodial user. Use createGroup() instead.');
        }

        $event = json_decode($signedEventJson);
        if ($event === null) {
            throw new \RuntimeException('Invalid event JSON');
        }

        // Validate event structure
        if (!isset($event->kind) || (int)$event->kind !== 40) {
            throw new \RuntimeException('Invalid event kind: expected 40');
        }

        if (!isset($event->pubkey) || $event->pubkey !== $creator->getPubkey()) {
            throw new \RuntimeException('Event pubkey does not match authenticated user');
        }

        if (!isset($event->sig) || $event->sig === '') {
            throw new \RuntimeException('Event is not signed');
        }

        if (!isset($event->id) || $event->id === '') {
            throw new \RuntimeException('Event has no id');
        }

        // Set the channel event ID and publish
        $group->setChannelEventId($event->id);
        $this->em->flush();

        $community = $group->getCommunity();
        $result = $this->relayClient->publish($signedEventJson, $community);
        if (!$result['ok']) {
            throw new \RuntimeException('Failed to publish channel event: ' . ($result['message'] ?? 'unknown'));
        }
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

