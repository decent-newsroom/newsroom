<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\VisitRepository;

#[ORM\Entity(repositoryClass: VisitRepository::class)]
class Visit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $route;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $visitedAt;

    public function __construct(string $route)
    {
        $this->route = $route;
        $this->visitedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoute(): string
    {
        return $this->route;
    }

    public function setRoute(string $route): self
    {
        $this->route = $route;
        return $this;
    }

    public function getVisitedAt(): \DateTimeImmutable
    {
        return $this->visitedAt;
    }
}
