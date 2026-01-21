<?php

namespace App\Entity;

use App\Repository\HighlightRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity storing article highlights (NIP-84, kind 9802)
 * Cached from Nostr relays for performance
 */
#[ORM\Entity(repositoryClass: HighlightRepository::class)]
#[ORM\Index(columns: ['article_coordinate'], name: 'idx_article_coordinate')]
#[ORM\Index(columns: ['event_id'], name: 'idx_event_id')]
#[ORM\Index(columns: ['created_at'], name: 'idx_created_at')]
class Highlight
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $eventId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $articleCoordinate = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $content = null;

    #[ORM\Column(length: 255)]
    private ?string $pubkey = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $createdAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $context = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $cachedAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawEvent = null;

    public function __construct()
    {
        $this->cachedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    public function setEventId(string $eventId): static
    {
        $this->eventId = $eventId;
        return $this;
    }

    public function getArticleCoordinate(): ?string
    {
        return $this->articleCoordinate;
    }

    public function setArticleCoordinate(?string $articleCoordinate): static
    {
        $this->articleCoordinate = $articleCoordinate;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getPubkey(): ?string
    {
        return $this->pubkey;
    }

    public function setPubkey(string $pubkey): static
    {
        $this->pubkey = $pubkey;
        return $this;
    }

    public function getCreatedAt(): ?int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function setContext(?string $context): static
    {
        $this->context = $context;
        return $this;
    }

    public function getCachedAt(): ?\DateTimeImmutable
    {
        return $this->cachedAt;
    }

    public function setCachedAt(\DateTimeImmutable $cachedAt): static
    {
        $this->cachedAt = $cachedAt;
        return $this;
    }

    public function getRawEvent(): ?array
    {
        return $this->rawEvent;
    }

    public function setRawEvent(?array $rawEvent): static
    {
        $this->rawEvent = $rawEvent;
        return $this;
    }
}

