<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HiddenCoordinateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stores coordinates (kind:pubkey:d-tag) that should be hidden from
 * public magazine/book listings. Used by admins to suppress test events
 * or malformed publications.
 */
#[ORM\Entity(repositoryClass: HiddenCoordinateRepository::class)]
#[ORM\Table(name: 'hidden_coordinate')]
#[ORM\UniqueConstraint(name: 'uniq_hidden_coordinate', columns: ['coordinate'])]
class HiddenCoordinate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 512)]
    private string $coordinate;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $coordinate, ?string $reason = null)
    {
        $this->coordinate = $coordinate;
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCoordinate(): string
    {
        return $this->coordinate;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $this->reason = $reason;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

