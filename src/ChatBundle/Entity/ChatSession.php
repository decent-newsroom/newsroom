<?php

declare(strict_types=1);

namespace App\ChatBundle\Entity;

use App\ChatBundle\Repository\ChatSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Long-lived login session for a chat user.
 * Token is stored as SHA-256 hash; the plaintext lives only in the cookie.
 */
#[ORM\Entity(repositoryClass: ChatSessionRepository::class)]
#[ORM\Table(name: 'chat_session')]
#[ORM\Index(name: 'chat_session_token', columns: ['session_token'])]
class ChatSession
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

    /** SHA-256 hash of the session token */
    #[ORM\Column(length: 128)]
    private string $sessionToken;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    // -- Validation --

    public function isValid(): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }
        if ($this->expiresAt < new \DateTimeImmutable()) {
            return false;
        }
        return true;
    }

    // -- Getters / Setters --

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

    public function getSessionToken(): string
    {
        return $this->sessionToken;
    }

    public function setSessionToken(string $sessionToken): self
    {
        $this->sessionToken = $sessionToken;
        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
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

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): self
    {
        $this->lastSeenAt = $lastSeenAt;
        return $this;
    }

    public function touchLastSeen(): self
    {
        $this->lastSeenAt = new \DateTimeImmutable();
        return $this;
    }
}

