<?php

declare(strict_types=1);

namespace App\ChatBundle\Entity;

use App\ChatBundle\Enum\ChatUserStatus;
use App\ChatBundle\Repository\ChatUserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Application account with a custodial Nostr identity.
 * The user does not manage keys directly — the system generates and stores them.
 */
#[ORM\Entity(repositoryClass: ChatUserRepository::class)]
#[ORM\Table(name: 'chat_user')]
#[ORM\UniqueConstraint(name: 'chat_user_community_pubkey', columns: ['community_id', 'pubkey'])]
class ChatUser implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatCommunity::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ChatCommunity $community;

    #[ORM\Column(length: 255)]
    private string $displayName;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $about = null;

    /** Hex-encoded Nostr public key */
    #[ORM\Column(length: 64)]
    private string $pubkey;

    /** AES-256-GCM encrypted private key (base64 encoded) */
    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedPrivateKey;

    #[ORM\Column(length: 20)]
    private string $status = ChatUserStatus::PENDING->value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    /**
     * Transient — set at runtime by the security layer based on community membership role.
     * @var string[]
     */
    private array $runtimeRoles = [];

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // -- UserInterface --

    public function getUserIdentifier(): string
    {
        return $this->pubkey;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_CHAT_USER'];
        foreach ($this->runtimeRoles as $role) {
            if (!in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
        return $roles;
    }

    /** Set by ChatUserProvider based on community membership role */
    public function setRuntimeRoles(array $roles): void
    {
        $this->runtimeRoles = $roles;
    }

    public function eraseCredentials(): void
    {
        // No browser-managed credentials
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

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): self
    {
        $this->displayName = $displayName;
        return $this;
    }

    public function getAbout(): ?string
    {
        return $this->about;
    }

    public function setAbout(?string $about): self
    {
        $this->about = $about;
        return $this;
    }

    public function getPubkey(): string
    {
        return $this->pubkey;
    }

    public function setPubkey(string $pubkey): self
    {
        $this->pubkey = $pubkey;
        return $this;
    }

    public function getEncryptedPrivateKey(): string
    {
        return $this->encryptedPrivateKey;
    }

    public function setEncryptedPrivateKey(string $encryptedPrivateKey): self
    {
        $this->encryptedPrivateKey = $encryptedPrivateKey;
        return $this;
    }

    public function getStatus(): ChatUserStatus
    {
        return ChatUserStatus::from($this->status);
    }

    public function setStatus(ChatUserStatus $status): self
    {
        $this->status = $status->value;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->getStatus() === ChatUserStatus::ACTIVE;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function setActivatedAt(?\DateTimeImmutable $activatedAt): self
    {
        $this->activatedAt = $activatedAt;
        return $this;
    }
}

