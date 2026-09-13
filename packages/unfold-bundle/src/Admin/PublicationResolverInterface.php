<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Admin;

use Symfony\Component\HttpFoundation\Request;

interface PublicationResolverInterface
{
    public function resolve(Request $request, string $pubkey): PublicationContext;
}
