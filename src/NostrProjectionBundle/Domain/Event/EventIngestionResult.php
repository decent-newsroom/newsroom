<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Domain\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventId;

final readonly class EventIngestionResult
{
    public function __construct(
        public EventId $eventId,
        public bool $inserted,
        public bool $currentRecordChanged,
    ) {
    }
}
