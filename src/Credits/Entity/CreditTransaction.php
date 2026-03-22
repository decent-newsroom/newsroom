<?php

namespace App\Credits\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @deprecated Since v0.0.21 — Search credits system has been deprecated.
 */
#[ORM\Entity]
class CreditTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(length: 64)]
    private string $npub;

    #[ORM\Column(type: 'integer')]
    private int $amount;

    #[ORM\Column(length: 16)]
    private string $type; // 'credit' or 'debit'

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    #[ORM\Column(nullable: true)]
    private ?string $reason = null;

    public function __construct(string $npub, int $amount, string $type, ?string $reason = null)
    {
        $this->npub = $npub;
        $this->amount = $amount;
        $this->type = $type;
        $this->createdAt = new \DateTime();
        $this->reason = $reason;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getNpub(): string
    {
        return $this->npub;
    }

    public function setNpub(string $npub): void
    {
        $this->npub = $npub;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): void
    {
        $this->amount = $amount;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): void
    {
        $this->reason = $reason;
    }

}

