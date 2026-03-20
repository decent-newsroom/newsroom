<?php

declare(strict_types=1);

namespace App\ChatBundle\Entity;

use App\ChatBundle\Enum\ChatRole;
use App\ChatBundle\Repository\ChatCommunityMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents a user's membership and role in a community.
 */
#[ORM\Entity(repositoryClass: ChatCommunityMembershipRepository::class)]
#[ORM\Table(name: 'chat_community_membership')]
#[ORM\UniqueConstraint(name: 'chat_cm_user_community', columns: ['user_id', 'community_id'])]
class ChatCommunityMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatUser::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ChatUser $user;

    #[ORM\ManyToOne(targetEntity: ChatCommunity::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ChatCommunity $community;

    #[ORM\Column(length: 20)]
    private string $role = ChatRole::USER->value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ChatUser
    {
        return $this->user;
    }

    public function setUser(ChatUser $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getCommunity(): ChatCommunity
    {
        return $this->community;
    }

    public function setCommunity(ChatCommunity $community): self
    {
        $this->community = $community;
        return $this;
    }

    public function getRole(): ChatRole
    {
        return ChatRole::from($this->role);
    }

    public function setRole(ChatRole $role): self
    {
        $this->role = $role->value;
        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }
}

