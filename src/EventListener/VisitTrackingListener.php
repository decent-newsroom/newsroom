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
        // TEMPORARILY DISABLED — diagnosing 502s for anonymous users
        return;
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // TEMPORARILY DISABLED — diagnosing 502s for anonymous users
        return;
    }
}
