<?php

namespace App\Entity;

use App\Repository\UnfoldSiteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps subdomains to Nostr magazine coordinates for Unfold rendering
 *
 * Coordinate format: kind:pubkey:identifier (e.g., 30040:abc123....:my-magazine)
 */
#[ORM\Entity(repositoryClass: UnfoldSiteRepository::class)]
#[ORM\Table(name: 'unfold_site')]
#[ORM\HasLifecycleCallbacks]
class UnfoldSite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $subdomain;

    /**
     * Magazine coordinate in format: kind:pubkey:identifier
     * Example: 30040:abc123...:my-magazine-slug
     */
    #[ORM\Column(length: 500)]
    private string $coordinate;

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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubdomain(): string
    {
        return $this->subdomain;
    }

    public function setSubdomain(string $subdomain): static
    {
        $this->subdomain = $subdomain;

        return $this;
    }

    public function getCoordinate(): string
    {
        return $this->coordinate;
    }

    public function setCoordinate(string $coordinate): static
    {
        $this->coordinate = $coordinate;

        return $this;
    }

    /**
     * @deprecated Use getCoordinate() instead
     */
    public function getNaddr(): string
    {
        return $this->coordinate;
    }

    /**
     * @deprecated Use setCoordinate() instead
     */
    public function setNaddr(string $naddr): static
    {
        $this->coordinate = $naddr;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}

