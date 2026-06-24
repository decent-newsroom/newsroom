<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Domain\Reference;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventId;

final readonly class StoredEventReference
{
    /**
     * @param array<int, string> $rawTag
     */
    public function __construct(
        public EventId $sourceEventId,
        public ReferenceType $type,
        public string $value,
        public ?string $marker = null,
        public ?string $relay = null,
        public array $rawTag = [],
    ) {
    }
}
