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
        return ($request->attributes->getBoolean('_unfold_admin') || $request->attributes->getBoolean('_unfold_onboarding')) && in_array($request->attributes->get('_route'), [
            'unfold_admin_host_overview', 'unfold_admin_host_settings',
            'unfold_admin_coordinate_overview', 'unfold_admin_coordinate_settings',
            'unfold_admin_host_about_prepare', 'unfold_admin_host_about_commit',
            'unfold_admin_coordinate_about_prepare', 'unfold_admin_coordinate_about_commit',
            'unfold_admin_host_content', 'unfold_admin_coordinate_content',
            'unfold_admin_host_category_prepare', 'unfold_admin_host_category_commit',
            'unfold_admin_coordinate_category_prepare', 'unfold_admin_coordinate_category_commit',
            'unfold_onboarding_basics', 'unfold_onboarding_discard',
            'unfold_onboarding_prepare_root', 'unfold_onboarding_commit_root',
        ], true);
    }
}
