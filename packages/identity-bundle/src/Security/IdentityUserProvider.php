<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Security;

use DecentNewsroom\IdentityBundle\Contract\IdentityOwnerInterface;
use DecentNewsroom\IdentityBundle\Contract\UserRepositoryBridgeInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Generic Symfony `UserProviderInterface` that resolves the host application's
 * user purely through {@see UserRepositoryBridgeInterface}, so the bundle
 * never needs to know about the host's concrete user class.
 *
 * The `$identifier` Symfony passes around (e.g. from the session, or from a
 * `UserBadge`) is the host user's `getUserIdentifier()` value. That keeps
 * legacy npub-backed sessions stable while still allowing future non-Nostr
 * accounts to use a generated local identifier.
 */
final class IdentityUserProvider implements UserProviderInterface
{
    public function __construct(private readonly UserRepositoryBridgeInterface $bridge)
    {
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof IdentityOwnerInterface) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $this->bridge->loadByOwnerId($user->getIdentityOwnerId());
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, IdentityOwnerInterface::class, true);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        try {
            return $this->bridge->loadByUserIdentifier($identifier);
        } catch (\RuntimeException $e) {
            throw new UserNotFoundException(previous: $e);
        }
    }
}
