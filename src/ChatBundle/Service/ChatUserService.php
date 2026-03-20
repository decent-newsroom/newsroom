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
use App\Entity\User;
use App\Util\NostrKeyUtil;
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

    /**
     * Create a self-sovereign chat admin linked to a main-app User.
     * No custodial keypair is generated — the admin signs events client-side with NIP-07/NIP-46.
     */
    public function createAdminFromMainUser(
        ChatCommunity $community,
        User $mainAppUser,
        ChatRole $role = ChatRole::ADMIN,
    ): ChatUser {
        // Check for existing linked account
        $existing = $this->userRepo->findByMainAppUserAndCommunity($mainAppUser, $community);
        if ($existing !== null) {
            throw new \RuntimeException('This user already has a chat account in this community');
        }

        $pubkey = NostrKeyUtil::npubToHex($mainAppUser->getNpub());
        $displayName = $mainAppUser->getDisplayName() ?: $mainAppUser->getNpub();

        $user = new ChatUser();
        $user->setCommunity($community);
        $user->setMainAppUser($mainAppUser);
        $user->setDisplayName($displayName);
        $user->setPubkey($pubkey);
        // No encryptedPrivateKey — self-sovereign user signs client-side
        $user->setStatus(ChatUserStatus::ACTIVE);
        $user->setActivatedAt(new \DateTimeImmutable());

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

