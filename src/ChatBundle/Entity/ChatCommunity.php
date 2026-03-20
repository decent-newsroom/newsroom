<?php

declare(strict_types=1);

namespace App\ChatBundle\Entity;

use App\ChatBundle\Enum\ChatCommunityStatus;
use App\ChatBundle\Repository\ChatCommunityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Top-level scope for a private chat environment, identified by subdomain.
 */
#[ORM\Entity(repositoryClass: ChatCommunityRepository::class)]
#[ORM\Table(name: 'chat_community')]
#[ORM\HasLifecycleCallbacks]
class ChatCommunity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $subdomain;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 20)]
    private string $status = ChatCommunityStatus::ACTIVE->value;

    /** Override relay URL per community (null = use global default) */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $relayUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // -- Getters / Setters --

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubdomain(): string
    {
        return $this->subdomain;
    }

    public function setSubdomain(string $subdomain): self
    {
        $this->subdomain = strtolower($subdomain);
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getStatus(): ChatCommunityStatus
    {
        return ChatCommunityStatus::from($this->status);
    }

    public function setStatus(ChatCommunityStatus $status): self
    {
        $this->status = $status->value;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->getStatus() === ChatCommunityStatus::ACTIVE;
    }

    public function getRelayUrl(): ?string
    {
        return $this->relayUrl;
    }

    public function setRelayUrl(?string $relayUrl): self
    {
        $this->relayUrl = $relayUrl;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

