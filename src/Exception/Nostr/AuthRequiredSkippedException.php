<?php

declare(strict_types=1);

namespace App\Exception\Nostr;

/**
 * Thrown by the relay gateway when an anonymous open is refused because the
 * cached NIP-11 document indicates `auth_required: true` and no signer is
 * available for this connection.
 *
 * Callers should treat this exactly like a normal connect failure (record
 * via {@see \App\Service\Nostr\RelayHealthStore}, defer the request, fall
 * back to other relays). The gateway logs it at INFO level rather than
 * WARNING since it is an *expected* skip, not a transient error.
 */
class AuthRequiredSkippedException extends \RuntimeException
{
}

