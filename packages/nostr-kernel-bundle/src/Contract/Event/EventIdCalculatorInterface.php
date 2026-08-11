<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Contract\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventId;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

interface EventIdCalculatorInterface
{
    public function calculate(NostrEvent $event): EventId;
}

