<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Event;

use DecentNewsroom\NostrKernelBundle\Exception\InvalidEventId;

final readonly class EventId
{
    public function __construct(private string $value)
    {
        if (!\preg_match('/^[a-f0-9]{64}$/', $value)) {
            throw new InvalidEventId('Event id must be a 64-char lowercase hex string.');
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

