<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

use App\ChatBundle\Entity\ChatCommunity;
use App\ChatBundle\Entity\ChatGroup;
use App\ChatBundle\Entity\ChatInvite;
use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Enum\ChatInviteType;
use App\ChatBundle\Repository\ChatInviteRepository;
use Doctrine\ORM\EntityManagerInterface;

class ChatInviteService
{
    public function __construct(
        private readonly ChatInviteRepository $inviteRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Generate an invite and return the plaintext token (shown once, never stored).
     */
    public function generateInvite(
        ChatCommunity $community,
        ChatInviteType $type,
        ChatUser $createdBy,
        string $roleToGrant = 'user',
        ?ChatGroup $group = null,
        ?int $maxUses = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): string {
        $plaintextToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plaintextToken);

        $invite = new ChatInvite();
        $invite->setCommunity($community);
        $invite->setType($type);
        $invite->setCreatedBy($createdBy);
        $invite->setRoleToGrant($roleToGrant);
        $invite->setTokenHash($tokenHash);
        $invite->setGroup($group);
        $invite->setMaxUses($maxUses);
        $invite->setExpiresAt($expiresAt);

        $this->em->persist($invite);
        $this->em->flush();

        return $plaintextToken;
    }

    public function validateToken(string $plaintextToken): ?ChatInvite
    {
        $tokenHash = hash('sha256', $plaintextToken);
        $invite = $this->inviteRepo->findByTokenHash($tokenHash);

        if ($invite === null || !$invite->isValid()) {
            return null;
        }

        return $invite;
    }

    public function redeemInvite(ChatInvite $invite): void
    {
        $invite->incrementUsedCount();
        $this->em->flush();
    }

    public function revokeInvite(ChatInvite $invite): void
    {
        $invite->setRevokedAt(new \DateTimeImmutable());
        $this->em->flush();
    }
}

