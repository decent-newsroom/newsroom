<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Identity;

use DecentNewsroom\NostrKernelBundle\Exception\InvalidNip19;

final readonly class Nprofile
{
    public function __construct(private string $value)
    {
        if ($value === '' || !\str_starts_with($value, 'nprofile1')) {
            throw new InvalidNip19('NPROFILE value must start with "nprofile1".');
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

