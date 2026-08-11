<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Coordinate;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidCoordinate;

final readonly class AddressableCoordinate implements EventCoordinate
{
    public function __construct(
        private EventKind $kind,
        private Pubkey $pubkey,
        private string $dTag,
    ) {
        if (!$this->kind->isAddressable()) {
            throw new InvalidCoordinate('Addressable coordinate requires an addressable event kind.');
        }

        if (\trim($this->dTag) === '') {
            throw new InvalidCoordinate('Addressable coordinate requires a non-empty d tag value.');
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

    public function dTag(): string
    {
        return $this->dTag;
    }

    public function toString(): string
    {
        return $this->kind->value() . ':' . $this->pubkey->toHex() . ':' . $this->dTag;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}

