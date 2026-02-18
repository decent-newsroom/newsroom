<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PublicationSubdomainStatus;
use App\Repository\PublicationSubdomainSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents a subscription for a publication subdomain (e.g., magazine.decentnewsroom.com)
 * Allows users to host their magazine on a subdomain for 20,000 sats/year
 */
#[ORM\Entity(repositoryClass: PublicationSubdomainSubscriptionRepository::class)]
#[ORM\Table(name: 'publication_subdomain_subscription')]
#[ORM\Index(columns: ['npub'], name: 'idx_pub_subdomain_npub')]
#[ORM\Index(columns: ['subdomain'], name: 'idx_pub_subdomain_name')]
#[ORM\Index(columns: ['status'], name: 'idx_pub_subdomain_status')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_pub_subdomain_expires')]
class PublicationSubdomainSubscription
{
    public const PRICE_SATS = 120000; // 120,000 sats per year
    public const DURATION_DAYS = 365; // 1 year

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $npub;

    #[ORM\Column(length: 255, unique: true)]
    private string $subdomain;

    /**
     * Magazine coordinate in format: kind:pubkey:identifier
     * Example: 30040:abc123...:my-magazine-slug
     */
    #[ORM\Column(length: 500)]
    private string $magazineCoordinate;

    #[ORM\Column(length: 20, enumType: PublicationSubdomainStatus::class)]
    private PublicationSubdomainStatus $status = PublicationSubdomainStatus::PENDING;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pendingInvoiceBolt11 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $paymentEventId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct(string $npub, string $subdomain, string $magazineCoordinate)
    {
        $this->npub = $npub;
        $this->subdomain = strtolower($subdomain);
        $this->magazineCoordinate = $magazineCoordinate;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNpub(): string
    {
        return $this->npub;
    }

    public function setNpub(string $npub): self
    {
        $this->npub = $npub;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getSubdomain(): string
    {
        return $this->subdomain;
    }

    public function setSubdomain(string $subdomain): self
    {
        $this->subdomain = strtolower($subdomain);
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getMagazineCoordinate(): string
    {
        return $this->magazineCoordinate;
    }

    public function setMagazineCoordinate(string $magazineCoordinate): self
    {
        $this->magazineCoordinate = $magazineCoordinate;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getStatus(): PublicationSubdomainStatus
    {
        return $this->status;
    }

    public function setStatus(PublicationSubdomainStatus $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getStartedAt(): ?\DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeInterface $startedAt): self
    {
        $this->startedAt = $startedAt;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getPendingInvoiceBolt11(): ?string
    {
        return $this->pendingInvoiceBolt11;
    }

    public function setPendingInvoiceBolt11(?string $pendingInvoiceBolt11): self
    {
        $this->pendingInvoiceBolt11 = $pendingInvoiceBolt11;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getPaymentEventId(): ?string
    {
        return $this->paymentEventId;
    }

    public function setPaymentEventId(?string $paymentEventId): self
    {
        $this->paymentEventId = $paymentEventId;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * Activate the subscription
     */
    public function activate(): self
    {
        $this->status = PublicationSubdomainStatus::ACTIVE;
        $this->startedAt = new \DateTime();
        $this->expiresAt = (new \DateTime())->modify('+' . self::DURATION_DAYS . ' days');
        $this->updatedAt = new \DateTime();
        return $this;
    }

    /**
     * Check if subscription is expired
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return $this->expiresAt < new \DateTime();
    }

    /**
     * Check if subscription is active and not expired
     */
    public function isActiveAndValid(): bool
    {
        return $this->status === PublicationSubdomainStatus::ACTIVE && !$this->isExpired();
    }

    /**
     * Renew the subscription (extend expiration by 1 year)
     */
    public function renew(): self
    {
        if ($this->expiresAt === null || $this->isExpired()) {
            // If expired or never set, start from now
            $this->expiresAt = (new \DateTime())->modify('+' . self::DURATION_DAYS . ' days');
        } else {
            // If still active, extend from current expiration
            $this->expiresAt->modify('+' . self::DURATION_DAYS . ' days');
        }
        $this->status = PublicationSubdomainStatus::ACTIVE;
        if ($this->startedAt === null) {
            $this->startedAt = new \DateTime();
        }
        $this->updatedAt = new \DateTime();
        return $this;
    }

    /**
     * Validate subdomain format
     */
    public static function isValidSubdomainFormat(string $subdomain): bool
    {
        // Must be lowercase letters, numbers, and hyphens only
        // Must be between 2-50 characters
        // Cannot start or end with hyphen
        return preg_match('/^[a-z0-9]([a-z0-9\-]{0,48}[a-z0-9])?$/', $subdomain) === 1;
    }
}

