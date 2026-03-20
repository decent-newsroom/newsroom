<?php

declare(strict_types=1);

namespace App\ChatBundle\EventListener;

use App\ChatBundle\Repository\ChatCommunityRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves incoming requests to a ChatCommunity by subdomain.
 *
 * Runs at priority 31 — just below UnfoldRequestListener (32) so that
 * Unfold subdomains take precedence. If the subdomain matches a chat
 * community, the listener sets the _chat_community request attribute.
 * Otherwise it falls through silently and lets normal routing handle it.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 31)]
class ChatRequestListener
{
    public function __construct(
        private readonly ChatCommunityRepository $communityRepository,
        private readonly string $baseDomain,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Skip if Unfold already claimed this request
        if ($request->attributes->has('_unfold_site')) {
            return;
        }

        $subdomain = $this->extractSubdomain($request);
        if ($subdomain === null) {
            return;
        }

        try {
            $community = $this->communityRepository->findBySubdomain($subdomain);
        } catch (\Throwable $e) {
            $this->logger?->error('ChatRequestListener: DB lookup failed', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if ($community !== null) {
            $request->attributes->set('_chat_community', $community);
            $request->attributes->set('_chat_subdomain', $subdomain);
        }
    }

    private function extractSubdomain(Request $request): ?string
    {
        $host = strtok($request->getHost(), ':');

        $baseParts = explode('.', $this->baseDomain);
        $hostParts = explode('.', $host);

        // Main domain or localhost — no subdomain
        if ($host === 'localhost' || $host === $this->baseDomain) {
            return null;
        }

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

        // Handle sub.localhost for local development
        if ($hostPartsCount === $basePartsCount + 1) {
            return $hostParts[0];
        }

        // Multi-level subdomain — return first part
        return $hostParts[0];
    }
}

