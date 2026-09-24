<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UpdateProStatus;
use App\Enum\UpdateProTier;
use App\Repository\UpdateProSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Paid Updates Pro subscription. Its status vocabulary is UpdateProStatus; the
 * persisted state strings remain pending, active, grace, and expired. Updates Pro
 * gates entitlement to heavier update source types and a higher per-user cap.
 */
#[ORM\Entity(repositoryClass: UpdateProSubscriptionRepository::class)]
#[ORM\Table(name: 'notification_pro_subscription')]
#[ORM\Index(columns: ['npub'], name: 'idx_notif_pro_npub')]
#[ORM\Index(columns: ['status'], name: 'idx_notif_pro_status')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_notif_pro_expires')]
class UpdateProSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $npub;

    #[ORM\Column(length: 20, enumType: UpdateProTier::class)]
    private UpdateProTier $tier;

    #[ORM\Column(length: 20, enumType: UpdateProStatus::class)]
    private UpdateProStatus $status = UpdateProStatus::PENDING;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $graceEndsAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pendingInvoiceBolt11 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $zapReceiptEventId = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct(string $npub, UpdateProTier $tier)
    {
        $this->npub = $npub;
        $this->tier = $tier;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }
    public function getNpub(): string { return $this->npub; }

    public function getTier(): UpdateProTier { return $this->tier; }
    public function setTier(UpdateProTier $tier): self
    {
        $this->tier = $tier;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getStatus(): UpdateProStatus { return $this->status; }
    public function setStatus(UpdateProStatus $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getStartedAt(): ?\DateTimeInterface { return $this->startedAt; }
    public function getExpiresAt(): ?\DateTimeInterface { return $this->expiresAt; }
    public function getGraceEndsAt(): ?\DateTimeInterface { return $this->graceEndsAt; }

    public function getPendingInvoiceBolt11(): ?string { return $this->pendingInvoiceBolt11; }
    public function setPendingInvoiceBolt11(?string $bolt11): self
    {
        $this->pendingInvoiceBolt11 = $bolt11;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getZapReceiptEventId(): ?string { return $this->zapReceiptEventId; }
    public function setZapReceiptEventId(?string $id): self
    {
        $this->zapReceiptEventId = $id;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }

    public function isActive(): bool { return $this->status->isActive(); }
    public function isExpired(): bool { return $this->status === UpdateProStatus::EXPIRED; }

    public function activate(): self
    {
        $now = new \DateTime();
        $this->startedAt = $now;
        $this->status = UpdateProStatus::ACTIVE;

        $expiresAt = clone $now;
        $expiresAt->modify('+' . $this->tier->getDurationDays() . ' days');
        $this->expiresAt = $expiresAt;

        $graceEndsAt = clone $expiresAt;
        $graceEndsAt->modify('+' . $this->tier->getGracePeriodDays() . ' days');
        $this->graceEndsAt = $graceEndsAt;

        $this->pendingInvoiceBolt11 = null;
        $this->updatedAt = $now;
        return $this;
    }

    public function renew(): self
    {
        $now = new \DateTime();
        $baseDate = ($this->expiresAt && $this->expiresAt > $now) ? $this->expiresAt : $now;

        $expiresAt = clone $baseDate;
        $expiresAt->modify('+' . $this->tier->getDurationDays() . ' days');
        $this->expiresAt = $expiresAt;

        $graceEndsAt = clone $expiresAt;
        $graceEndsAt->modify('+' . $this->tier->getGracePeriodDays() . ' days');
        $this->graceEndsAt = $graceEndsAt;

        $this->status = UpdateProStatus::ACTIVE;
        $this->pendingInvoiceBolt11 = null;
        $this->updatedAt = $now;
        return $this;
    }

    public function getDaysRemaining(): ?int
    {
        if (!$this->expiresAt) {
            return null;
        }
        $diff = (new \DateTime())->diff($this->expiresAt);
        return $diff->invert ? -$diff->days : $diff->days;
    }
}

