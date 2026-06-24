<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventValidatorInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventValidationResult;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

final readonly class InnisEventValidator implements EventValidatorInterface
{
    public function validate(NostrEvent $event): EventValidationResult
    {
        // TODO(next pass): wire to innis/nostr-core validator once vendor API is inspected.
        throw new \LogicException('Innis event validation adapter is not wired yet; inspect innis/nostr-core APIs in the next pass.');
    }
}

