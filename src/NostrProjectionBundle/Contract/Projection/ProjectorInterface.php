<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Contract\Projection;

use DecentNewsroom\NostrKernelBundle\Domain\Event\VerifiedNostrEvent;
use DecentNewsroom\NostrProjectionBundle\Domain\Projection\ProjectionResult;

interface ProjectorInterface
{
    public function supports(VerifiedNostrEvent $event): bool;

    public function project(VerifiedNostrEvent $event): ProjectionResult;
}
