<?php

namespace App\Entity;

use App\Repository\UnfoldSiteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps subdomains to Nostr magazine naddrs for Unfold rendering
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

    #[ORM\Column(length: 500)]
    private string $naddr;

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

    public function getNaddr(): string
    {
        return $this->naddr;
    }

    public function setNaddr(string $naddr): static
    {
        $this->naddr = $naddr;

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

