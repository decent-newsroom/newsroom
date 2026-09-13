<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final readonly class HostPublicationResolver implements PublicationResolverInterface
{
    public function __construct(private PublicationSettingsManager $settings) {}

    public function resolve(Request $request, string $pubkey): PublicationContext
    {
        $site = $request->attributes->get('_unfold_site');
        try {
            if (!$site instanceof PublicationSite) {
                throw new \InvalidArgumentException();
            }
            $coordinate = PublicationSettings::normalizeCoordinate($site->coordinate);
        } catch (\InvalidArgumentException $e) {
            throw new ServiceUnavailableHttpException(null, 'Publication hosting needs operator repair.', $e);
        }
        [, $owner] = explode(':', $coordinate, 3);
        if ($owner !== $pubkey) {
            throw new AccessDeniedHttpException();
        }
        return new PublicationContext($coordinate, $this->settings->get($coordinate), PublicationMount::SUBDOMAIN,
            '/admin', $site, $request->getSchemeAndHttpHost());
    }
}
