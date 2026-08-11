<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Auth\NostrHttpAuthValidatorInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Auth\NostrHttpAuthResult;
use DecentNewsroom\NostrKernelBundle\Domain\Auth\NostrHttpAuthToken;

final readonly class InnisHttpAuthValidator implements NostrHttpAuthValidatorInterface
{
    public function validate(NostrHttpAuthToken $token): NostrHttpAuthResult
    {
        // TODO(next pass): wire to innis/nostr-core HTTP auth validator after API validation.
        throw new \LogicException('Innis HTTP auth adapter is not wired yet; inspect innis/nostr-core APIs in the next pass.');
    }
}

