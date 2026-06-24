<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Domain\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventId;

final readonly class CurrentRecord
{
    public function __construct(
        public string $coordinate,
        public EventId $eventId,
        public string $pubkey,
        public int $kind,
        public ?string $dTag,
        public int $createdAt,
        public bool $changed,
    ) {
    }
}
