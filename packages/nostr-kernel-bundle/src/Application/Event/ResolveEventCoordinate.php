<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Application\Event;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventCoordinateResolverInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Coordinate\AddressableCoordinate;
use DecentNewsroom\NostrKernelBundle\Domain\Coordinate\EventCoordinate;
use DecentNewsroom\NostrKernelBundle\Domain\Coordinate\ReplaceableCoordinate;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

final readonly class ResolveEventCoordinate implements EventCoordinateResolverInterface
{
    public function resolve(NostrEvent $event): ?EventCoordinate
    {
        $kind = $event->kind();

        if ($kind->isAddressable()) {
            $dTag = $event->tags()->dTag();
            if ($dTag === null) {
                return null;
            }

            return new AddressableCoordinate($kind, $event->pubkey(), $dTag);
        }

        if ($kind->isReplaceable()) {
            return new ReplaceableCoordinate($kind, $event->pubkey());
        }

        return null;
    }
}

