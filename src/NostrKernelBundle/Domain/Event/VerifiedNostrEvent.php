<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Event;

final readonly class VerifiedNostrEvent
{
    public function __construct(
        private NostrEvent $event,
        private EventValidationResult $validation,
    ) {
    }

    public function event(): NostrEvent
    {
        return $this->event;
    }

    public function validation(): EventValidationResult
    {
        return $this->validation;
    }
}

