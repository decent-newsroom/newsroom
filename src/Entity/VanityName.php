<?php

namespace App\Entity;

use App\Enum\VanityNamePaymentType;
use App\Enum\VanityNameStatus;
use App\Repository\VanityNameRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VanityNameRepository::class)]
#[ORM\Table(name: 'vanity_name')]
#[ORM\Index(columns: ['vanity_name'], name: 'idx_vanity_name')]
#[ORM\Index(columns: ['npub'], name: 'idx_vanity_npub')]
#[ORM\Index(columns: ['pubkey_hex'], name: 'idx_vanity_pubkey')]
#[ORM\Index(columns: ['status'], name: 'idx_vanity_status')]
class VanityName
{
    /**
     * Reserved vanity names that cannot be registered by users
     */
    public const RESERVED_NAMES = [
        'admin', 'administrator', 'system', 'support', 'help',
        'nostr', 'bitcoin', 'lightning', 'zap', 'btc', 'ln',
        'root', 'moderator', 'mod', 'staff', 'team', 'official',
        'api', 'www', 'mail', 'ftp', 'dns', 'ns1', 'ns2',
        '_', 'null', 'undefined', 'test', 'demo',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'vanity_name', length: 255)] // removed unique: true
    private string $vanityName;

    #[ORM\Column(length: 255)]
    private string $npub;

    #[ORM\Column(name: 'pubkey_hex', length: 64)]
    private string $pubkeyHex;

    #[ORM\Column(length: 20, enumType: VanityNameStatus::class)]
    private VanityNameStatus $status = VanityNameStatus::PENDING;

    #[ORM\Column(name: 'payment_type', length: 20, enumType: VanityNamePaymentType::class)]
    private VanityNamePaymentType $paymentType;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $relays = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pendingInvoiceBolt11 = null;

    public function __construct(string $vanityName, string $npub, string $pubkeyHex, VanityNamePaymentType $paymentType)
    {
        $this->vanityName = strtolower($vanityName);
        $this->npub = $npub;
        $this->pubkeyHex = $pubkeyHex;
        $this->paymentType = $paymentType;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVanityName(): string
    {
        return $this->vanityName;
    }

    public function setVanityName(string $vanityName): self
    {
        $this->vanityName = strtolower($vanityName);
        $this->updatedAt = new \DateTime();
        return $this;
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

    public function getPubkeyHex(): string
    {
        return $this->pubkeyHex;
    }

    public function setPubkeyHex(string $pubkeyHex): self
    {
        $this->pubkeyHex = $pubkeyHex;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getStatus(): VanityNameStatus
    {
        return $this->status;
    }

    public function setStatus(VanityNameStatus $status): self
    {
        $this->status = $status;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getPaymentType(): VanityNamePaymentType
    {
        return $this->paymentType;
    }

    public function setPaymentType(VanityNamePaymentType $paymentType): self
    {
        $this->paymentType = $paymentType;
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

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getRelays(): ?array
    {
        return $this->relays;
    }

    public function setRelays(?array $relays): self
    {
        $this->relays = $relays;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    public function getPendingInvoiceBolt11(): ?string
    {
        return $this->pendingInvoiceBolt11;
    }

    public function setPendingInvoiceBolt11(?string $bolt11): self
    {
        $this->pendingInvoiceBolt11 = $bolt11;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    /**
     * Check if the vanity name is expired
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false; // Lifetime vanity names never expire
        }
        return $this->expiresAt < new \DateTime();
    }

    /**
     * Get remaining days until expiration
     */
    public function getDaysRemaining(): ?int
    {
        if ($this->expiresAt === null) {
            return null; // Lifetime
        }
        $now = new \DateTime();
        if ($this->expiresAt < $now) {
            return 0;
        }
        return (int) $now->diff($this->expiresAt)->days;
    }

    /**
     * Activate the vanity name after payment
     */
    public function activate(): self
    {
        $this->status = VanityNameStatus::ACTIVE;
        $this->pendingInvoiceBolt11 = null;

        // Set expiration based on payment type
        $durationDays = $this->paymentType->getDurationInDays();
        if ($durationDays !== null) {
            $this->expiresAt = (new \DateTime())->modify("+{$durationDays} days");
        } else {
            $this->expiresAt = null; // Lifetime
        }

        $this->updatedAt = new \DateTime();
        return $this;
    }

    /**
     * Suspend the vanity name
     */
    public function suspend(): self
    {
        $this->status = VanityNameStatus::SUSPENDED;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    /**
     * Release the vanity name
     */
    public function release(): self
    {
        $this->status = VanityNameStatus::RELEASED;
        $this->updatedAt = new \DateTime();
        return $this;
    }

    /**
     * Validate vanity name format (NIP-05 compliant)
     */
    public static function isValidFormat(string $name): bool
    {
        // Only allow a-z0-9-_. (case-insensitive, will be lowercased)
        return preg_match('/^[a-z0-9\-_.]+$/i', $name) === 1
            && strlen($name) >= 2
            && strlen($name) <= 50;
    }

    /**
     * Check if name is reserved
     */
    public static function isReserved(string $name): bool
    {
        return in_array(strtolower($name), self::RESERVED_NAMES, true);
    }

    /**
     * Get NIP-05 identifier for this vanity name
     */
    public function getNip05Identifier(string $domain): string
    {
        return $this->vanityName . '@' . $domain;
    }
}

