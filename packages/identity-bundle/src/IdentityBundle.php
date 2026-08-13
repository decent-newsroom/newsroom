<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle;

use DecentNewsroom\IdentityBundle\DependencyInjection\IdentityExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * IdentityBundle — extensible multi-method authentication and identity linking.
 *
 * Lets a single host application user authenticate through any number of
 * independent identity providers (Nostr, email OTP, passkey, OAuth, ...),
 * tracked via {@see Entity\UserIdentityLink} rows keyed by (provider, externalId)
 * rather than a hard foreign key to the host's user entity.
 */
class IdentityBundle extends Bundle
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function getContainerExtension(): IdentityExtension
    {
        return new IdentityExtension();
    }
}
