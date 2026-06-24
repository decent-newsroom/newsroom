<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Coordinate;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidCoordinate;

final readonly class ReplaceableCoordinate implements EventCoordinate
{
    public function __construct(
        private EventKind $kind,
        private Pubkey $pubkey,
    ) {
        if (!$this->kind->isReplaceable() && !$this->kind->isAddressable()) {
            throw new InvalidCoordinate('Replaceable coordinate requires replaceable or addressable event kind.');
        }
    }

    public function kind(): EventKind
    {
        return $this->kind;
    }

    public function pubkey(): Pubkey
    {
        return $this->pubkey;
    }

    public function toString(): string
    {
        return $this->kind->value() . ':' . $this->pubkey->toHex();
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}

