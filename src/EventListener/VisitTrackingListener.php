<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Visit;
use App\Repository\VisitRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 0)]
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

    public function __construct(
        private readonly VisitRepository $visitRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only track main requests, not sub-requests
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->getPathInfo();

        foreach (self::EXCLUDED_ROUTES as $excludedRoute) {
            if (str_starts_with($route, $excludedRoute)) {
                return;
            }
        }

        // Entire tracking block is wrapped in try/catch so that a DB or Redis failure
        // never crashes the FrankenPHP worker (which would produce a 502).
        try {
            // Get session ID for all visitors (both logged-in and anonymous)
            $sessionId = null;
            if ($request->hasSession()) {
                $session = $request->getSession();
                // Start session if not already started to get/create a session ID
                if (!$session->isStarted()) {
                    $session->start();
                }
                $sessionId = $session->getId();
            }

            // Create and save the visit record
            $visit = new Visit($route, $sessionId);
            $this->visitRepository->save($visit);
        } catch (\Throwable $e) {
            // Silently fail — visit tracking must never break the actual request.
            // Log at warning level so it's visible but not alarming.
            $this->logger?->warning('VisitTrackingListener: failed to record visit', [
                'route' => $route,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
