<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MagazineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Magazine entity - projection of Nostr magazine indices (kind 30040)
 * Represents the complete magazine structure with nested categories and articles
 */
#[ORM\Entity(repositoryClass: MagazineRepository::class)]
#[ORM\Table(name: 'magazine')]
#[ORM\Index(columns: ['slug'], name: 'idx_magazine_slug')]
#[ORM\Index(columns: ['created_at'], name: 'idx_magazine_created_at')]
#[ORM\Index(columns: ['published_at'], name: 'idx_magazine_published_at')]
class Magazine
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: "IDENTITY")]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    private string $slug;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $image = null;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    private ?string $language = null;

    /**
     * @var array<string> Magazine tags
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $tags = [];

    /**
     * @var array<array{slug: string, title: string, summary: string, articleCount: int}> Category metadata
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $categories = [];

    /**
     * @var array<string> Deduped list of contributor pubkeys from all articles
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $contributors = [];

    /**
     * @var array<string> Deduped list of relay URLs from all contributor relay lists
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $relayPool = [];

    /**
     * @var array<int> Deduped list of event kinds contained in the magazine
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $containedKinds = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $pubkey = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): self
    {
        $this->summary = $summary;
        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): self
    {
        $this->image = $image;
        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): self
    {
        $this->language = $language;
        return $this;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setTags(array $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    public function getCategories(): array
    {
        return $this->categories;
    }

    public function setCategories(array $categories): self
    {
        $this->categories = $categories;
        return $this;
    }

    public function getContributors(): array
    {
        return $this->contributors;
    }

    public function setContributors(array $contributors): self
    {
        $this->contributors = $contributors;
        return $this;
    }

    public function getRelayPool(): array
    {
        return $this->relayPool;
    }

    public function setRelayPool(array $relayPool): self
    {
        $this->relayPool = $relayPool;
        return $this;
    }

    public function getContainedKinds(): array
    {
        return $this->containedKinds;
    }

    public function setContainedKinds(array $containedKinds): self
    {
        $this->containedKinds = $containedKinds;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    public function getPubkey(): ?string
    {
        return $this->pubkey;
    }

    public function setPubkey(?string $pubkey): self
    {
        $this->pubkey = $pubkey;
        return $this;
    }

    /**
     * Update the updatedAt timestamp
     */
    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
