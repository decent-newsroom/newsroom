<?php

namespace App\Entity;

use App\Repository\NzineRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NzineRepository::class)]
class Nzine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $npub = null;

    #[ORM\OneToOne(targetEntity: NzineBot::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?NzineBot $nzineBot = null;

    #[ORM\Column(type: Types::JSON)]
    private array|ArrayCollection $mainCategories;

    #[ORM\Column(nullable: true)]
    private ?array $lists = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $editor = null;

    /**
     * Slug (d-tag) of the main index event that contains all the main category indices
     * @var string|null
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(type: 'string')]
    private string $state = 'draft';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $feedUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastFetchedAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $feedConfig = null;

    public function __construct()
    {
        $this->mainCategories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNpub(): ?string
    {
        return $this->npub;
    }

    public function setNpub(string $npub): static
    {
        $this->npub = $npub;

        return $this;
    }

    public function getNsec(): ?string
    {
        return $this->nsec;
    }

    public function setNsec(?string $nsec): void
    {
        $this->nsec = $nsec;
    }

    public function getMainCategories(): array
    {
        return $this->mainCategories;
    }

    public function setMainCategories(array $mainCategories): static
    {
        $this->mainCategories = $mainCategories;

        return $this;
    }

    public function getLists(): ?array
    {
        return $this->lists;
    }

    public function setLists(?array $lists): static
    {
        $this->lists = $lists;

        return $this;
    }

    public function getEditor(): ?string
    {
        return $this->editor;
    }

    public function setEditor(?string $editor): static
    {
        $this->editor = $editor;

        return $this;
    }

    public function getNzineBot(): ?NzineBot
    {
        return $this->nzineBot;
    }

    public function setNzineBot(?NzineBot $nzineBot): void
    {
        $this->nzineBot = $nzineBot;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): void
    {
        $this->slug = $slug;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
    }

    public function getFeedUrl(): ?string
    {
        return $this->feedUrl;
    }

    public function setFeedUrl(?string $feedUrl): void
    {
        $this->feedUrl = $feedUrl;
    }

    public function getLastFetchedAt(): ?\DateTimeImmutable
    {
        return $this->lastFetchedAt;
    }

    public function setLastFetchedAt(?\DateTimeImmutable $lastFetchedAt): void
    {
        $this->lastFetchedAt = $lastFetchedAt;
    }

    public function getFeedConfig(): ?array
    {
        return $this->feedConfig;
    }

    public function setFeedConfig(?array $feedConfig): void
    {
        $this->feedConfig = $feedConfig;
    }
}
