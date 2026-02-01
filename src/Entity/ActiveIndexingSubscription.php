<?php

namespace App\Entity;

use App\Enum\ActiveIndexingStatus;
use App\Enum\ActiveIndexingTier;
use App\Repository\ActiveIndexingSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActiveIndexingSubscriptionRepository::class)]
#[ORM\Table(name: 'active_indexing_subscription')]
#[ORM\Index(columns: ['npub'], name: 'idx_subscription_npub')]
#[ORM\Index(columns: ['status'], name: 'idx_subscription_status')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_subscription_expires')]
class ActiveIndexingSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $npub;

    #[ORM\Column(length: 20, enumType: ActiveIndexingTier::class)]
    private ActiveIndexingTier $tier;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $graceEndsAt = null;

    #[ORM\Column(length: 20, enumType: ActiveIndexingStatus::class)]
    private ActiveIndexingStatus $status = ActiveIndexingStatus::PENDING;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $useNip65Relays = true;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $customRelays = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pendingInvoiceBolt11 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $zapReceiptEventId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastFetchedAt = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $articlesIndexed = 0;

    public function __construct(string $npub, ActiveIndexingTier $tier)
    {
        $this->npub = $npub;
        $this->tier = $tier;
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
        return $this;
    }

    public function getTier(): ActiveIndexingTier
    {
        return $this->tier;
    }

    public function setTier(ActiveIndexingTier $tier): self
    {
        $this->tier = $tier;
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

    public function getGraceEndsAt(): ?\DateTimeInterface
    {
        return $this->graceEndsAt;
    }

    public function setGraceEndsAt(?\DateTimeInterface $graceEndsAt): self
    {
        $this->graceEndsAt = $graceEndsAt;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getStatus(): ActiveIndexingStatus
    {
        return $this->status;
    }

    public function setStatus(ActiveIndexingStatus $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function isUseNip65Relays(): bool
    {
        return $this->useNip65Relays;
    }

    public function setUseNip65Relays(bool $useNip65Relays): self
    {
        $this->useNip65Relays = $useNip65Relays;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getCustomRelays(): ?array
    {
        return $this->customRelays;
    }

    public function setCustomRelays(?array $customRelays): self
    {
        $this->customRelays = $customRelays;
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

    public function getZapReceiptEventId(): ?string
    {
        return $this->zapReceiptEventId;
    }

    public function setZapReceiptEventId(?string $zapReceiptEventId): self
    {
        $this->zapReceiptEventId = $zapReceiptEventId;
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

    public function getLastFetchedAt(): ?\DateTimeInterface
    {
        return $this->lastFetchedAt;
    }

    public function setLastFetchedAt(?\DateTimeInterface $lastFetchedAt): self
    {
        $this->lastFetchedAt = $lastFetchedAt;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getArticlesIndexed(): int
    {
        return $this->articlesIndexed;
    }

    public function setArticlesIndexed(int $articlesIndexed): self
    {
        $this->articlesIndexed = $articlesIndexed;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function incrementArticlesIndexed(int $count = 1): self
    {
        $this->articlesIndexed += $count;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    /**
     * Check if subscription is currently active (including grace period)
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Check if subscription has expired (past grace period)
     */
    public function isExpired(): bool
    {
        return $this->status === ActiveIndexingStatus::EXPIRED;
    }

    /**
     * Check if subscription is in grace period
     */
    public function isInGracePeriod(): bool
    {
        return $this->status === ActiveIndexingStatus::GRACE;
    }

    /**
     * Activate the subscription after payment verification
     */
    public function activate(): self
    {
        $now = new \DateTime();
        $this->startedAt = $now;
        $this->status = ActiveIndexingStatus::ACTIVE;

        // Calculate expiry based on tier
        $expiresAt = clone $now;
        $expiresAt->modify('+' . $this->tier->getDurationDays() . ' days');
        $this->expiresAt = $expiresAt;

        // Calculate grace period end
        $graceEndsAt = clone $expiresAt;
        $graceEndsAt->modify('+' . $this->tier->getGracePeriodDays() . ' days');
        $this->graceEndsAt = $graceEndsAt;

        // Clear pending invoice
        $this->pendingInvoiceBolt11 = null;

        $this->updatedAt = $now;
        return $this;
    }

    /**
     * Renew the subscription (extend from current expiry or now if expired)
     */
    public function renew(): self
    {
        $now = new \DateTime();

        // If still active, extend from current expiry; otherwise start fresh
        $baseDate = ($this->expiresAt && $this->expiresAt > $now) ? $this->expiresAt : $now;

        $expiresAt = clone $baseDate;
        $expiresAt->modify('+' . $this->tier->getDurationDays() . ' days');
        $this->expiresAt = $expiresAt;

        $graceEndsAt = clone $expiresAt;
        $graceEndsAt->modify('+' . $this->tier->getGracePeriodDays() . ' days');
        $this->graceEndsAt = $graceEndsAt;

        $this->status = ActiveIndexingStatus::ACTIVE;
        $this->pendingInvoiceBolt11 = null;
        $this->updatedAt = $now;

        return $this;
    }

    /**
     * Get days remaining until expiry (negative if expired)
     */
    public function getDaysRemaining(): ?int
    {
        if (!$this->expiresAt) {
            return null;
        }

        $now = new \DateTime();
        $diff = $now->diff($this->expiresAt);

        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * Get the effective relays to use for fetching
     */
    public function getEffectiveRelays(): ?array
    {
        if (!$this->useNip65Relays && !empty($this->customRelays)) {
            return $this->customRelays;
        }
        return null; // Indicates to use NIP-65 relay discovery
    }
}
