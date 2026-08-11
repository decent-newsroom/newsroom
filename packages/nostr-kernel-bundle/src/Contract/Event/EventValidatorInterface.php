<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Contract\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventValidationResult;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

interface EventValidatorInterface
{
    public function validate(NostrEvent $event): EventValidationResult;
}

