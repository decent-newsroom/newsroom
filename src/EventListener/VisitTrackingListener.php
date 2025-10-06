<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Visit;
use App\Repository\VisitRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Bundle\SecurityBundle\Security;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 0)]
class VisitTrackingListener
{
    private const EXCLUDED_ROUTES = [
        '/api/',
        '/_profiler',
        '/_wdt',
        '/service-worker.js',
        '/robots.txt',
        '/assets/',
        '/icons/',
    ];

    public function __construct(
        private readonly VisitRepository $visitRepository,
        private readonly Security $security,
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

        // Skip tracking for excluded routes (API, profiler, assets, etc.)
        foreach (self::EXCLUDED_ROUTES as $excludedRoute) {
            if (str_starts_with($route, $excludedRoute)) {
                return;
            }
        }

        // Get session ID if user is logged in
        $sessionId = null;
        if ($this->security->getUser()) {
            // Start session if not already started
            if (!$request->hasSession() || !$request->getSession()->isStarted()) {
                $request->getSession()->start();
            }
            $sessionId = $request->getSession()->getId();
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
