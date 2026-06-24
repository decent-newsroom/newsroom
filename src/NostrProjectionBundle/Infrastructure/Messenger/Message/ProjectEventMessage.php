<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Infrastructure\Messenger\Message;

final readonly class ProjectEventMessage
{
    public function __construct(
        public string $eventId,
    ) {
    }
}
