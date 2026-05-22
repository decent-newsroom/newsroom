<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EssayistMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks Essayist membership grants attributed to a specific zap receipt.
 *
 * One row per zap receipt (`zapReceiptEventId` is unique → idempotency / replay
 * protection). Each row extends the membership window by `duration_days`.
 * The latest non-expired row's `expiresAt` is the effective expiry; the
 * companion role `ROLE_ESSAYIST_MEMBER` is granted/revoked by the service
 * layer to match.
 */
#[ORM\Entity(repositoryClass: EssayistMembershipRepository::class)]
#[ORM\Table(name: 'essayist_membership')]
#[ORM\Index(name: 'idx_essayist_membership_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_essayist_membership_expires_at', columns: ['expires_at'])]
class EssayistMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Hex pubkey of the payer (denormalized for lookups before User row exists). */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $payerPubkey;

    /** Hex pubkey of the existing member who received the zap (the "sponsor"). */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $contributedToPubkey;

    /** Kind 9735 event id of the zap receipt that minted this grant. Unique. */
    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $zapReceiptEventId;

    #[ORM\Column(type: Types::INTEGER)]
    private int $amountSats = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now');
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getPayerPubkey(): string { return $this->payerPubkey; }
    public function setPayerPubkey(string $payerPubkey): self { $this->payerPubkey = $payerPubkey; return $this; }

    public function getContributedToPubkey(): string { return $this->contributedToPubkey; }
    public function setContributedToPubkey(string $pubkey): self { $this->contributedToPubkey = $pubkey; return $this; }

    public function getZapReceiptEventId(): string { return $this->zapReceiptEventId; }
    public function setZapReceiptEventId(string $id): self { $this->zapReceiptEventId = $id; return $this; }

    public function getAmountSats(): int { return $this->amountSats; }
    public function setAmountSats(int $amountSats): self { $this->amountSats = $amountSats; return $this; }

    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(\DateTimeImmutable $startedAt): self { $this->startedAt = $startedAt; return $this; }

    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function isActive(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now');
        return $this->expiresAt > $now;
    }
}

