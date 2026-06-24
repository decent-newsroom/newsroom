<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Coordinate;

interface EventCoordinate
{
    public function toString(): string;
}

