<?php

declare(strict_types=1);

namespace App\ChatBundle\Entity;

use App\ChatBundle\Repository\ChatGroupMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents a user's membership and role inside a group.
 */
#[ORM\Entity(repositoryClass: ChatGroupMembershipRepository::class)]
#[ORM\Table(name: 'chat_group_membership')]
#[ORM\UniqueConstraint(name: 'chat_gm_user_group', columns: ['user_id', 'group_id'])]
class ChatGroupMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatUser::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ChatUser $user;

    #[ORM\ManyToOne(targetEntity: ChatGroup::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ChatGroup $group;

    /** 'admin' or 'member' */
    #[ORM\Column(length: 20)]
    private string $role = 'member';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $joinedAt;

    /** Per-user per-group mute for push notifications */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $mutedNotifications = false;

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

    public function getGroup(): ChatGroup
    {
        return $this->group;
    }

    public function setGroup(ChatGroup $group): self
    {
        $this->group = $group;
        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function isMutedNotifications(): bool
    {
        return $this->mutedNotifications;
    }

    public function setMutedNotifications(bool $mutedNotifications): self
    {
        $this->mutedNotifications = $mutedNotifications;
        return $this;
    }
}

