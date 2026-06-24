<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Contract\Auth;

use DecentNewsroom\NostrKernelBundle\Domain\Auth\NostrHttpAuthResult;
use DecentNewsroom\NostrKernelBundle\Domain\Auth\NostrHttpAuthToken;

interface NostrHttpAuthValidatorInterface
{
    public function validate(NostrHttpAuthToken $token): NostrHttpAuthResult;
}

