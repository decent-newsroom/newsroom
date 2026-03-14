<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FollowPackPurpose;
use App\Repository\FollowPackSourceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FollowPackSourceRepository::class)]
#[ORM\Table(name: 'follow_pack_source')]
class FollowPackSource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true, enumType: FollowPackPurpose::class)]
    private FollowPackPurpose $purpose;

    #[ORM\Column(length: 500)]
    private string $coordinate;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getPurpose(): FollowPackPurpose { return $this->purpose; }

    public function setPurpose(FollowPackPurpose $purpose): static
    {
        $this->purpose = $purpose;
        return $this;
    }

    public function getCoordinate(): string { return $this->coordinate; }

    public function setCoordinate(string $coordinate): static
    {
        $this->coordinate = $coordinate;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}

