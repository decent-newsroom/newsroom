<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Contract\Projection;

use DecentNewsroom\NostrKernelBundle\Domain\Event\VerifiedNostrEvent;

interface ProjectionDispatcherInterface
{
    public function dispatch(VerifiedNostrEvent $event): void;
}
