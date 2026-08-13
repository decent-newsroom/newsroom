<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Exception;

final class LastIdentityException extends \RuntimeException
{
    public static function forOwnerId(string $ownerId): self
    {
        return new self(sprintf(
            'Cannot unlink the last remaining identity for owner "%s"; at least one must remain.',
            $ownerId,
        ));
    }
}
