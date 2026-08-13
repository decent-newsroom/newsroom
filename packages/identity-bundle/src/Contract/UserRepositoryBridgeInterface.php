<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Contract;

/**
 * Bridges IdentityBundle to the host application's own user persistence layer.
 * The host provides exactly one implementation of this interface (e.g.
 * `App\Security\AppIdentityBridge`), wired against its concrete user entity and
 * repository, so the bundle never needs to know about `App\Entity\User` directly.
 */
interface UserRepositoryBridgeInterface
{
    /**
     * Find the host user already linked to (provider, externalId), or create a
     * brand new host user and link it, if this is a first-time login through
     * this identity.
     */
    public function findOrCreateByIdentity(string $provider, string $externalId): IdentityOwnerInterface;

    /**
     * Load a host user by its stable owner id (see {@see IdentityOwnerInterface}).
     *
     * @throws \RuntimeException if no such user exists
     */
    public function loadByOwnerId(string $ownerId): IdentityOwnerInterface;

    /**
     * Load a host user by its Symfony Security identifier. For legacy Nostr
     * accounts this is the npub; for future non-Nostr accounts this is the
     * generated local identifier.
     *
     * @throws \RuntimeException if no such user exists
     */
    public function loadByUserIdentifier(string $identifier): IdentityOwnerInterface;
}
