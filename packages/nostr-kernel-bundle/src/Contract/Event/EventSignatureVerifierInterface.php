<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Contract\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

interface EventSignatureVerifierInterface
{
    public function verify(NostrEvent $event): bool;
}

