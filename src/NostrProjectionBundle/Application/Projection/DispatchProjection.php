<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Application\Projection;

use DecentNewsroom\NostrKernelBundle\Domain\Event\VerifiedNostrEvent;
use DecentNewsroom\NostrProjectionBundle\Contract\Projection\ProjectionDispatcherInterface;
use DecentNewsroom\NostrProjectionBundle\Contract\Projection\ProjectorInterface;

final readonly class DispatchProjection implements ProjectionDispatcherInterface
{
    /**
     * @param iterable<ProjectorInterface> $projectors
     */
    public function __construct(
        private iterable $projectors,
    ) {
    }

    public function dispatch(VerifiedNostrEvent $event): void
    {
        foreach ($this->projectors as $projector) {
            if ($projector->supports($event)) {
                $projector->project($event);
            }
        }
    }
}
