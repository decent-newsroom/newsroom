<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Contract\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Coordinate\EventCoordinate;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

interface EventCoordinateResolverInterface
{
    public function resolve(NostrEvent $event): ?EventCoordinate;
}

