<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EssayistZapClaimRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks pending zap claims waiting for verification.
 *
 * Members can claim membership by providing either a zap receipt event ID or a BOLT11 invoice.
 * The claim is initially PENDING, can be VERIFIED (auto or manual), or REJECTED.
 * Once verified, the claim triggers `EssayistMembershipService::recordGrant()`.
 */
#[ORM\Entity(repositoryClass: EssayistZapClaimRepository::class)]
#[ORM\Table(name: 'essayist_zap_claim')]
#[ORM\Index(name: 'idx_essayist_zap_claim_status', columns: ['status'])]
#[ORM\Index(name: 'idx_essayist_zap_claim_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_essayist_zap_claim_created_at', columns: ['created_at'])]
class EssayistZapClaim
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Hex pubkey of the payer (denormalized for lookups). */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $payerPubkey;

    /** Hex pubkey of the existing member who received the zap (the sponsor). */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $sponsorPubkey;

    /** Kind 9735 zap receipt event ID (if provided) — may be null if only invoice was provided. */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $zapReceiptEventId = null;

    /** BOLT11 invoice provided by the member for verification. */
    #[ORM\Column(type: Types::STRING, length: 1024, nullable: true)]
    private ?string $bolt11Invoice = null;

    /**
     * Payment preimage (64-char hex, 32 bytes) provided by the payer.
     * sha256(preimage) == payment_hash from BOLT11 → trustless proof of payment.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $paymentPreimage = null;

    /** Claimed amount in sats (may be derived from invoice or user input). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $claimedAmountSats = null;

    /**
     * Status: pending | verified | rejected
     * pending = claim submitted, awaiting verification
     * verified = verified and membership grant created
     * rejected = rejected by admin or failed verification
     */
    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $status = 'pending';

    /** Reason for rejection (if status = rejected). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    /** Verification method used: auto_receipt | auto_invoice | manual */
    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $verificationMethod = null;

    /** Hex pubkey of the recipient member who attested the payment. */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $recipientAttestorPubkey = null;

    /** Optional kind:9741 event id if recipient shared/published one. */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $recipientAttestationEventId = null;

    /** Optional note from recipient when attesting. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recipientAttestationNote = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getPayerPubkey(): string
    {
        return $this->payerPubkey;
    }

    public function setPayerPubkey(string $payerPubkey): self
    {
        $this->payerPubkey = $payerPubkey;
        return $this;
    }

    public function getSponsorPubkey(): string
    {
        return $this->sponsorPubkey;
    }

    public function setSponsorPubkey(string $sponsorPubkey): self
    {
        $this->sponsorPubkey = $sponsorPubkey;
        return $this;
    }

    public function getZapReceiptEventId(): ?string
    {
        return $this->zapReceiptEventId;
    }

    public function setZapReceiptEventId(?string $zapReceiptEventId): self
    {
        $this->zapReceiptEventId = $zapReceiptEventId;
        return $this;
    }

    public function getBolt11Invoice(): ?string
    {
        return $this->bolt11Invoice;
    }

    public function setBolt11Invoice(?string $bolt11Invoice): self
    {
        $this->bolt11Invoice = $bolt11Invoice;
        return $this;
    }

    public function getPaymentPreimage(): ?string
    {
        return $this->paymentPreimage;
    }

    public function setPaymentPreimage(?string $paymentPreimage): self
    {
        $this->paymentPreimage = $paymentPreimage;
        return $this;
    }

    public function getClaimedAmountSats(): ?int
    {
        return $this->claimedAmountSats;
    }

    public function setClaimedAmountSats(?int $claimedAmountSats): self
    {
        $this->claimedAmountSats = $claimedAmountSats;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): self
    {
        $this->rejectionReason = $rejectionReason;
        return $this;
    }

    public function getVerificationMethod(): ?string
    {
        return $this->verificationMethod;
    }

    public function setVerificationMethod(?string $verificationMethod): self
    {
        $this->verificationMethod = $verificationMethod;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRecipientAttestorPubkey(): ?string
    {
        return $this->recipientAttestorPubkey;
    }

    public function setRecipientAttestorPubkey(?string $recipientAttestorPubkey): self
    {
        $this->recipientAttestorPubkey = $recipientAttestorPubkey;
        return $this;
    }

    public function getRecipientAttestationEventId(): ?string
    {
        return $this->recipientAttestationEventId;
    }

    public function setRecipientAttestationEventId(?string $recipientAttestationEventId): self
    {
        $this->recipientAttestationEventId = $recipientAttestationEventId;
        return $this;
    }

    public function getRecipientAttestationNote(): ?string
    {
        return $this->recipientAttestationNote;
    }

    public function setRecipientAttestationNote(?string $recipientAttestationNote): self
    {
        $this->recipientAttestationNote = $recipientAttestationNote;
        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): self
    {
        $this->verifiedAt = $verifiedAt;
        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}



