<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Visit;
use App\Repository\VisitRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Cookie;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 0)]
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onKernelResponse', priority: 0)]
class VisitTrackingListener
{
    private const EXCLUDED_ROUTES = [
        '/_profiler',
        '/_wdt',
        '/service-worker.js',
        '/manifest.webmanifest',
        '/robots.txt',
        '/up',
        '/assets/',
        '/icons/',
    ];

    /** Cookie name for anonymous visitor continuity tracking */
    private const VISITOR_COOKIE = '_nv';

    /** Cookie lifetime: 30 days */
    private const VISITOR_COOKIE_TTL = 30 * 86400;

    /** Set by onKernelRequest when a new visitor ID needs to be sent */
    private ?string $newVisitorId = null;

    public function __construct(
        private readonly VisitRepository $visitRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Reset per-request state immediately. In FrankenPHP worker mode this
        // listener is a singleton — without this, a crash before the response
        // phase would leak newVisitorId into the next request.
        $this->newVisitorId = null;

        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->getPathInfo();

        if ($this->isExcludedRoute($route)) {
            return;
        }

        try {
            $visitorId = null;

            // Only touch the session if a session cookie already exists.
            // This avoids triggering session start (and Redis) for anonymous visitors.
            $sessionCookieName = \ini_get('session.name') ?: 'PHPSESSID';
            if ($request->cookies->has($sessionCookieName)
                && $request->hasSession()
                && $request->getSession()->isStarted()
            ) {
                $visitorId = $request->getSession()->getId();
            }

            if (!$visitorId) {
                $visitorId = $request->cookies->get(self::VISITOR_COOKIE);
                if (!$visitorId) {
                    $visitorId = bin2hex(random_bytes(16));
                    $this->newVisitorId = $visitorId;
                }
            }

            $visit = new Visit($route, $visitorId, $request->headers->get('referer'), $request->attributes->get('_unfold_subdomain'));
            $this->visitRepository->save($visit);
        } catch (\Throwable $e) {
            $this->logger?->warning('VisitTrackingListener: failed to record visit', [
                'route' => $route,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || $this->newVisitorId === null) {
            return;
        }

        $visitorId = $this->newVisitorId;
        $this->newVisitorId = null;

        try {
            $response = $event->getResponse();
            $response->headers->setCookie(new Cookie(
                self::VISITOR_COOKIE,
                $visitorId,
                time() + self::VISITOR_COOKIE_TTL,
                '/',
                null,
                null,   // secure — let Symfony decide based on request
                true,   // httpOnly
                false,  // raw
                'lax'   // sameSite
            ));
        } catch (\Throwable $e) {
            // Never crash for analytics
        }
    }

    private function isExcludedRoute(string $route): bool
    {
        foreach (self::EXCLUDED_ROUTES as $excludedRoute) {
            if (str_starts_with($route, $excludedRoute)) {
                return true;
            }
        }

        return false;
    }
}
