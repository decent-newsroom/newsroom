<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

use Symfony\Component\HttpFoundation\Request;

interface PublicationAdminIdentityInterface
{
    /** Null means anonymous; authenticated identities must be valid hex pubkeys. */
    public function pubkey(): ?string;
    public function loginUrl(Request $request): string;
}
