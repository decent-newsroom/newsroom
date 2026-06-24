<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventIdCalculatorInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventId;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

final readonly class InnisEventIdCalculator implements EventIdCalculatorInterface
{
    public function calculate(NostrEvent $event): EventId
    {
        // TODO(next pass): wire to innis/nostr-core event id calculator after API validation.
        throw new \LogicException('Innis event id calculator adapter is not wired yet; inspect innis/nostr-core APIs in the next pass.');
    }
}

