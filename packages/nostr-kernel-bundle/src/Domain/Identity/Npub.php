<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Identity;

use DecentNewsroom\NostrKernelBundle\Exception\InvalidNip19;

final readonly class Npub
{
    public function __construct(private string $value)
    {
        if ($value === '' || !\str_starts_with($value, 'npub1')) {
            throw new InvalidNip19('NPUB value must start with "npub1".');
        }
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

