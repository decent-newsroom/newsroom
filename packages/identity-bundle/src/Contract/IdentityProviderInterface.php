<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Contract;

use Symfony\Component\HttpFoundation\Request;

/**
 * One implementation per authentication method (Nostr, email OTP, passkey,
 * OAuth, ...). Providers are responsible only for proving "this request is
 * authentically from externalId under my protocol" — resolving that externalId
 * to a host user happens via {@see UserRepositoryBridgeInterface}, so providers
 * never need to know about the host's user entity.
 */
interface IdentityProviderInterface
{
    /**
     * Stable machine name for this provider, e.g. "nostr", "email_otp",
     * "passkey", "oauth_google". Used as the `provider` column value on
     * {@see \DecentNewsroom\IdentityBundle\Entity\UserIdentityLink}.
     */
    public function getName(): string;

    /**
     * Whether this provider should attempt to handle the given request.
     */
    public function supports(Request $request): bool;

    /**
     * Prove the request belongs to one external identity and return that
     * provider-specific external id, such as a Nostr hex pubkey or normalized
     * email address.
     */
    public function authenticate(Request $request): string;
}
