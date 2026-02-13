<?php

namespace App\UnfoldBundle\EventListener;

use App\Repository\UnfoldSiteRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Listens for incoming requests and sets an attribute if the request is for an Unfold subdomain.
 *
 * This enables dynamic subdomain routing by:
 * 1. Extracting the subdomain from the Host header
 * 2. Checking if it's a reserved subdomain (relay, www, api, etc.)
 * 3. Looking up the subdomain in the database (UnfoldSite entity)
 * 4. Setting a request attribute that controllers can use
 *
 * Priority is set high (32) to run before routing occurs.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 32)]
class UnfoldRequestListener
{
    /**
     * Reserved subdomains that should not be handled by Unfold
     */
    private const RESERVED_SUBDOMAINS = [
        'relay',    // Nostr relay
        'www',      // Main website
        'api',      // API endpoints
        'admin',    // Admin panel (if separate)
        'mail',     // Mail server
        'smtp',     // SMTP server
        'imap',     // IMAP server
        'pop',      // POP server
        'ftp',      // FTP server
        'cdn',      // CDN
        'static',   // Static assets
        'assets',   // Assets
    ];

    public function __construct(
        private readonly UnfoldSiteRepository $unfoldSiteRepository,
        private readonly string $baseDomain,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $subdomain = $this->extractSubdomain($request);

        if ($subdomain === null) {
            // No subdomain - this is the main domain, let normal routing handle it
            return;
        }

        if ($this->isReservedSubdomain($subdomain)) {
            // Reserved subdomain - let normal routing handle it (e.g., relay)
            return;
        }

        // Look up the subdomain in the database
        $unfoldSite = $this->unfoldSiteRepository->findBySubdomain($subdomain);

        if ($unfoldSite !== null) {
            // Set request attributes so the SiteController can use them
            $request->attributes->set('_unfold_site', $unfoldSite);
            $request->attributes->set('_unfold_subdomain', $subdomain);

            // Mark this request as an Unfold request for route matching
            $request->attributes->set('_route_params', array_merge(
                $request->attributes->get('_route_params', []),
                ['_unfold' => true]
            ));
        }
    }

    /**
     * Extract subdomain from the request's Host header
     */
    private function extractSubdomain(Request $request): ?string
    {
        $host = $request->getHost();

        // Remove port if present
        $host = strtok($host, ':');

        // Get the base domain parts
        $baseParts = explode('.', $this->baseDomain);
        $hostParts = explode('.', $host);

        // For localhost development (e.g., support.localhost)
        if ($host === 'localhost' || $host === $this->baseDomain) {
            return null;
        }

        // Check if this is a subdomain of the base domain
        $basePartsCount = count($baseParts);
        $hostPartsCount = count($hostParts);

        if ($hostPartsCount <= $basePartsCount) {
            return null;
        }

        // Verify the base domain matches
        $hostBaseParts = array_slice($hostParts, -$basePartsCount);
        if ($hostBaseParts !== $baseParts) {
            return null;
        }

        // Handle single-level subdomains (e.g., support.example.com → support)
        // Handle localhost special case (e.g., support.localhost → support)
        if ($hostPartsCount === $basePartsCount + 1) {
            return $hostParts[0];
        }

        // Handle multi-level subdomains - return the first part
        return $hostParts[0];
    }

    /**
     * Check if a subdomain is reserved and should not be handled by Unfold
     */
    private function isReservedSubdomain(string $subdomain): bool
    {
        return in_array(strtolower($subdomain), self::RESERVED_SUBDOMAINS, true);
    }
}

