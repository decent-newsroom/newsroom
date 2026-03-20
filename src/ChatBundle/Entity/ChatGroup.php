<?php

declare(strict_types=1);

namespace App\ChatBundle\Entity;

use App\ChatBundle\Enum\ChatGroupStatus;
use App\ChatBundle\Repository\ChatGroupRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A chat group (channel) inside a community.
 * Each group maps to a NIP-28 kind 40 channel create event.
 */
#[ORM\Entity(repositoryClass: ChatGroupRepository::class)]
#[ORM\Table(name: 'chat_group')]
#[ORM\UniqueConstraint(name: 'chat_group_community_slug', columns: ['community_id', 'slug'])]
class ChatGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatCommunity::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ChatCommunity $community;

    #[ORM\Column(length: 255)]
    private string $slug;

    #[ORM\Column(length: 255)]
    private string $name;

    /** NIP-28 kind 40 event ID that created this channel */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $channelEventId = null;

    #[ORM\Column(length: 20)]
    private string $status = ChatGroupStatus::ACTIVE->value;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = strtolower($slug);
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

    public function getChannelEventId(): ?string
    {
        return $this->channelEventId;
    }

    public function setChannelEventId(?string $channelEventId): self
    {
        $this->channelEventId = $channelEventId;
        return $this;
    }

    public function getStatus(): ChatGroupStatus
    {
        return ChatGroupStatus::from($this->status);
    }

    public function setStatus(ChatGroupStatus $status): self
    {
        $this->status = $status->value;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->getStatus() === ChatGroupStatus::ACTIVE;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

