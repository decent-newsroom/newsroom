<?php

declare(strict_types=1);

namespace App\Unfold;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/** Only actual publication routes bypass the platform-admin role gate. */
final class PublicationAdminRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        return $request->attributes->getBoolean('_unfold_admin') && in_array($request->attributes->get('_route'), [
            'unfold_admin_host_overview', 'unfold_admin_host_settings',
            'unfold_admin_coordinate_overview', 'unfold_admin_coordinate_settings',
        ], true);
    }
}
