<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Contract\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

interface EventNormalizerInterface
{
    /**
     * @param array<string, mixed> $rawEvent
     */
    public function normalize(array $rawEvent): NostrEvent;
}

