<?php

namespace App\UnfoldBundle\Http;

use App\Entity\UnfoldSite;
use App\Repository\UnfoldSiteRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves HTTP Host header to an UnfoldSite (subdomain → naddr mapping)
 */
class HostResolver
{
    public function __construct(
        private readonly UnfoldSiteRepository $unfoldSiteRepository,
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * Extract subdomain from current request's Host header and look up UnfoldSite
     */
    public function resolve(): ?UnfoldSite
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        $host = $request->getHost();
        $subdomain = $this->extractSubdomain($host);

        if ($subdomain === null) {
            return null;
        }

        return $this->unfoldSiteRepository->findBySubdomain($subdomain);
    }

    /**
     * Resolve by explicit subdomain (useful for testing or direct lookup)
     */
    public function resolveBySubdomain(string $subdomain): ?UnfoldSite
    {
        return $this->unfoldSiteRepository->findBySubdomain($subdomain);
    }

    /**
     * Extract subdomain from a full host string
     * e.g., "support.example.com" → "support"
     *       "example.com" → null
     *       "localhost" → null
     */
    private function extractSubdomain(string $host): ?string
    {
        // Remove port if present
        $host = strtok($host, ':');

        // Split by dots
        $parts = explode('.', $host);

        // Need at least 3 parts for a subdomain (sub.domain.tld)
        // Or 2 parts for local dev (sub.localhost)
        if (count($parts) >= 3) {
            return $parts[0];
        }

        // Handle local development: sub.localhost
        if (count($parts) === 2 && $parts[1] === 'localhost') {
            return $parts[0];
        }

        return null;
    }
}

