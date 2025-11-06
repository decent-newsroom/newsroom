<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Visit;
use App\Repository\VisitRepository;
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
        '/assets/',
        '/icons/',
    ];

    public function __construct(
        private readonly VisitRepository $visitRepository
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

        try {
            $this->visitRepository->save($visit);
        } catch (\Exception $e) {
            // Silently fail to avoid breaking the request
            // You could log this error if needed
        }
    }
}
