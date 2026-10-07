<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BannedPubkeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BannedPubkeyRepository::class)]
#[ORM\Table(name: 'banned_pubkey')]
class BannedPubkey
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $pubkey;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $addedAt;

    #[ORM\Column(length: 255)]
    private string $addedBy;

    public function __construct(string $pubkey, string $reason, string $addedBy)
    {
        $pubkey = strtolower(trim($pubkey));
        if (preg_match('/^[a-f0-9]{64}$/D', $pubkey) !== 1) {
            throw new \InvalidArgumentException('A ban requires a 64-character hex pubkey.');
        }
        $this->pubkey = $pubkey;
        $this->reason = $reason;
        $this->addedBy = $addedBy;
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getPubkey(): string
    {
        return $this->pubkey;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function getAddedBy(): string
    {
        return $this->addedBy;
    }
}
