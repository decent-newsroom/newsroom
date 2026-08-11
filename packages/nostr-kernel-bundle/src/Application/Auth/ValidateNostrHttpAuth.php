<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Application\Auth;

use DecentNewsroom\NostrKernelBundle\Contract\Auth\NostrHttpAuthValidatorInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Auth\NostrHttpAuthResult;
use DecentNewsroom\NostrKernelBundle\Domain\Auth\NostrHttpAuthToken;

final readonly class ValidateNostrHttpAuth
{
    public function __construct(private NostrHttpAuthValidatorInterface $validator)
    {
    }

    public function __invoke(NostrHttpAuthToken $token): NostrHttpAuthResult
    {
        return $this->validator->validate($token);
    }
}

