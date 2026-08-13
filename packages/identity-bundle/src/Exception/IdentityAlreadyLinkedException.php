<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Exception;

final class IdentityAlreadyLinkedException extends \RuntimeException
{
    public static function forExternalId(string $provider, string $externalId): self
    {
        return new self(sprintf(
            'The %s identity "%s" is already linked to another account.',
            $provider,
            $externalId,
        ));
    }
}
