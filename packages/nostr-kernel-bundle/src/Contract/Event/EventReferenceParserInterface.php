<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Contract\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventReference;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

interface EventReferenceParserInterface
{
    /**
     * @return list<EventReference>
     */
    public function parse(NostrEvent $event): array;
}

