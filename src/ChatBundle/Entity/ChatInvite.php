<?php

declare(strict_types=1);

namespace App\ChatBundle\Entity;

use App\ChatBundle\Enum\ChatInviteType;
use App\ChatBundle\Repository\ChatInviteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * High-entropy invite token for user activation or group joining.
 * Tokens are stored hashed (SHA-256). The plaintext is shown once and never stored.
 */
#[ORM\Entity(repositoryClass: ChatInviteRepository::class)]
#[ORM\Table(name: 'chat_invite')]
#[ORM\Index(name: 'chat_invite_token_hash', columns: ['token_hash'])]
class ChatInvite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatCommunity::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ChatCommunity $community;

    /** Scoped to a group if set */
    #[ORM\ManyToOne(targetEntity: ChatGroup::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?ChatGroup $group = null;

    /** SHA-256 hash of the plaintext token */
    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 20)]
    private string $type = ChatInviteType::ACTIVATION->value;

    /** Role to grant when redeemed */
    #[ORM\Column(length: 20)]
    private string $roleToGrant = 'user';

    #[ORM\ManyToOne(targetEntity: ChatUser::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ChatUser $createdBy;

    /** null = unlimited */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $maxUses = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $usedCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // -- Validation helpers --

    public function isValid(): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }
        if ($this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable()) {
            return false;
        }
        if ($this->maxUses !== null && $this->usedCount >= $this->maxUses) {
            return false;
        }
        return true;
    }

    public function incrementUsedCount(): self
    {
        $this->usedCount++;
        return $this;
    }

    // -- Getters / Setters --

    public function getId(): ?int
    {
        return $this->id;
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

    public function getGroup(): ?ChatGroup
    {
        return $this->group;
    }

    public function setGroup(?ChatGroup $group): self
    {
        $this->group = $group;
        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;
        return $this;
    }

    public function getType(): ChatInviteType
    {
        return ChatInviteType::from($this->type);
    }

    public function setType(ChatInviteType $type): self
    {
        $this->type = $type->value;
        return $this;
    }

    public function getRoleToGrant(): string
    {
        return $this->roleToGrant;
    }

    public function setRoleToGrant(string $roleToGrant): self
    {
        $this->roleToGrant = $roleToGrant;
        return $this;
    }

    public function getCreatedBy(): ChatUser
    {
        return $this->createdBy;
    }

    public function setCreatedBy(ChatUser $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function setMaxUses(?int $maxUses): self
    {
        $this->maxUses = $maxUses;
        return $this;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): self
    {
        $this->revokedAt = $revokedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

