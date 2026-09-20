<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\VisitRepository;

#[ORM\Entity(repositoryClass: VisitRepository::class)]
class Visit
{
    private const ROUTE_MAX_LENGTH = 255;
    private const SESSION_ID_MAX_LENGTH = 255;
    private const REFERER_MAX_LENGTH = 2048;
    private const SUBDOMAIN_MAX_LENGTH = 255;
    private const USER_AGENT_MAX_LENGTH = 512;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $route;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $visitedAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sessionId = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $referer = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subdomain = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isBot = false;

    public function __construct(
        string $route,
        ?string $sessionId = null,
        ?string $referer = null,
        ?string $subdomain = null,
        ?string $userAgent = null,
        bool $isBot = false,
    ) {
        $this->route = self::truncate($route, self::ROUTE_MAX_LENGTH);
        $this->sessionId = self::truncateNullable($sessionId, self::SESSION_ID_MAX_LENGTH);
        $this->referer = self::truncateNullable($referer, self::REFERER_MAX_LENGTH);
        $this->subdomain = self::truncateNullable($subdomain, self::SUBDOMAIN_MAX_LENGTH);
        $this->userAgent = self::truncateNullable($userAgent, self::USER_AGENT_MAX_LENGTH);
        $this->isBot = $isBot;
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
        $this->route = self::truncate($route, self::ROUTE_MAX_LENGTH);
        return $this;
    }

    public function getVisitedAt(): \DateTimeImmutable
    {
        return $this->visitedAt;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(?string $sessionId): self
    {
        $this->sessionId = self::truncateNullable($sessionId, self::SESSION_ID_MAX_LENGTH);
        return $this;
    }

    public function getReferer(): ?string
    {
        return $this->referer;
    }

    public function setReferer(?string $referer): self
    {
        $this->referer = self::truncateNullable($referer, self::REFERER_MAX_LENGTH);
        return $this;
    }

    public function getSubdomain(): ?string
    {
        return $this->subdomain;
    }

    public function setSubdomain(?string $subdomain): self
    {
        $this->subdomain = self::truncateNullable($subdomain, self::SUBDOMAIN_MAX_LENGTH);
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = self::truncateNullable($userAgent, self::USER_AGENT_MAX_LENGTH);
        return $this;
    }

    public function isBot(): bool
    {
        return $this->isBot;
    }

    public function setIsBot(bool $isBot): self
    {
        $this->isBot = $isBot;
        return $this;
    }

    private static function truncate(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
    }

    private static function truncateNullable(?string $value, int $length): ?string
    {
        return $value === null ? null : self::truncate($value, $length);
    }
}
