<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Contract\Store;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventId;

interface EventReferenceStoreInterface
{
    /**
     * @param iterable<object> $references Usually NostrKernelBundle EventReference objects.
     */
    public function replaceForEvent(EventId $eventId, iterable $references): void;
}
