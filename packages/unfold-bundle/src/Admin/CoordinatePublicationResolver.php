<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final readonly class CoordinatePublicationResolver implements PublicationResolverInterface
{
    public function __construct(
        private PublicationSettingsManager $settings,
        private EventReadGatewayInterface $events,
        private SiteRegistryInterface $sites,
    ) {}

    public function resolve(Request $request, string $pubkey): PublicationContext
    {
        $dtag = (string) $request->attributes->get('mag');
        try {
            $coordinate = PublicationSettings::normalizeCoordinate('30040:' . $pubkey . ':' . $dtag);
        } catch (\InvalidArgumentException $e) {
            throw new NotFoundHttpException(previous: $e);
        }
        try {
            $event = $this->events->findByCoordinate($coordinate);
        } catch (\Throwable $e) {
            throw new ServiceUnavailableHttpException(null, 'Publication lookup unavailable.', $e);
        }
        $eventDtag = null;
        foreach ($event?->tags ?? [] as $tag) {
            if (($tag[0] ?? null) === 'd') {
                $eventDtag = $tag[1] ?? null;
                break;
            }
        }
        if ($event === null || $event->kind !== 30040 || strtolower($event->pubkey) !== $pubkey || $eventDtag !== $dtag) {
            throw new NotFoundHttpException();
        }
        $site = $this->sites->findByCoordinate($coordinate);
        $port = $request->getPort();
        $authority = $request->getHost() . (in_array($port, [80, 443], true) ? '' : ':' . $port);
        $publicUrl = $site === null ? null : $request->getScheme() . '://' . $site->subdomain . '.' . $authority;
        return new PublicationContext($coordinate, $this->settings->get($coordinate), PublicationMount::COORDINATE,
            '/mag/' . rawurlencode($dtag) . '/admin', $site, $publicUrl);
    }
}
