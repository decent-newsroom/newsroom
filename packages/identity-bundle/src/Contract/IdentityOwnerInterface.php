<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Contract;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Implemented by the host application's user entity so IdentityBundle can link
 * identities to it without a hard Doctrine relation (keeps the bundle usable by
 * any host, not just this application's `App\Entity\User`).
 */
interface IdentityOwnerInterface extends UserInterface
{
    /**
     * A stable, opaque identifier for this user, unique within the host
     * application. Typically the primary key cast to string.
     */
    public function getIdentityOwnerId(): string;
}
