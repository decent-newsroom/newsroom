<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Identity;

use DecentNewsroom\NostrKernelBundle\Exception\InvalidPubkey;

final readonly class Pubkey
{
    public function __construct(private string $hex)
    {
        if (!\preg_match('/^[a-f0-9]{64}$/', $hex)) {
            throw new InvalidPubkey('Pubkey must be a 64-char lowercase hex string.');
        }
    }

    public function toHex(): string
    {
        return $this->hex;
    }

    public function __toString(): string
    {
        return $this->hex;
    }
}

