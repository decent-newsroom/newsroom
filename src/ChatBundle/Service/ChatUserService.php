<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

use App\ChatBundle\Entity\ChatCommunity;
use App\ChatBundle\Entity\ChatCommunityMembership;
use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Enum\ChatRole;
use App\ChatBundle\Enum\ChatUserStatus;
use App\ChatBundle\Repository\ChatCommunityMembershipRepository;
use App\ChatBundle\Repository\ChatUserRepository;
use Doctrine\ORM\EntityManagerInterface;

class ChatUserService
{
    public function __construct(
        private readonly ChatKeyManager $keyManager,
        private readonly ChatUserRepository $userRepo,
        private readonly ChatCommunityMembershipRepository $membershipRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    public function createUser(ChatCommunity $community, string $displayName, ChatRole $role = ChatRole::USER): ChatUser
    {
        $keypair = $this->keyManager->generateKeypair();

        $user = new ChatUser();
        $user->setCommunity($community);
        $user->setDisplayName($displayName);
        $user->setPubkey($keypair['pubkey']);
        $user->setEncryptedPrivateKey($keypair['encryptedPrivateKey']);
        $user->setStatus(ChatUserStatus::PENDING);

        $this->em->persist($user);

        $membership = new ChatCommunityMembership();
        $membership->setUser($user);
        $membership->setCommunity($community);
        $membership->setRole($role);
        $this->em->persist($membership);

        $this->em->flush();

        return $user;
    }

    public function activateUser(ChatUser $user): void
    {
        $user->setStatus(ChatUserStatus::ACTIVE);
        $user->setActivatedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function suspendUser(ChatUser $user): void
    {
        $user->setStatus(ChatUserStatus::SUSPENDED);
        $this->em->flush();
    }
}

