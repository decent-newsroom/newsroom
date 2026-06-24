<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventSignatureVerifierInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

final readonly class InnisSignatureVerifier implements EventSignatureVerifierInterface
{
    public function verify(NostrEvent $event): bool
    {
        // TODO(next pass): wire to innis/nostr-core signature verifier after API validation.
        throw new \LogicException('Innis signature verifier adapter is not wired yet; inspect innis/nostr-core APIs in the next pass.');
    }
}

