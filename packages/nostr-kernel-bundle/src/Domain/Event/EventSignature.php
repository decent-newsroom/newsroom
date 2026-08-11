<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Event;

use DecentNewsroom\NostrKernelBundle\Exception\InvalidSignature;

final readonly class EventSignature
{
    public function __construct(private string $value)
    {
        if (!\preg_match('/^[a-f0-9]{128}$/', $value)) {
            throw new InvalidSignature('Event signature must be a 128-char lowercase hex string.');
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

