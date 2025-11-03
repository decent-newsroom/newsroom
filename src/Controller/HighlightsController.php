<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\NostrClient;
use nostriphant\NIP19\Bech32;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class HighlightsController extends AbstractController
{
    private const CACHE_TTL = 3600; // 1 hour in seconds
    private const MAX_DISPLAY_HIGHLIGHTS = 50;

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/highlights', name: 'highlights')]
    public function index(CacheInterface $cache): Response
    {
        try {
            // Cache key for highlights
            $cacheKey = 'global_article_highlights';
            // $cache->delete($cacheKey);
            // Get highlights from cache or fetch fresh
            $highlights = $cache->get($cacheKey, function (ItemInterface $item) {
                $item->expiresAfter(self::CACHE_TTL);

                try {
                    // Fetch highlights that reference articles (kind 30023)
                    $events = $this->nostrClient->getArticleHighlights(self::MAX_DISPLAY_HIGHLIGHTS);

                    // Process and enrich the highlights
                    return $this->processHighlights($events);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to fetch highlights', [
                        'error' => $e->getMessage()
                    ]);
                    return [];
                }
            });

            return $this->render('pages/highlights.html.twig', [
                'highlights' => $highlights,
                'total' => count($highlights),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error loading highlights page', [
                'error' => $e->getMessage()
            ]);

            return $this->render('pages/highlights.html.twig', [
                'highlights' => [],
                'total' => 0,
                'error' => 'Unable to load highlights at this time. Please try again later.',
            ]);
        }
    }

    /**
     * Process highlights to extract metadata
     */
    private function processHighlights(array $events): array
    {
        $processed = [];

        foreach ($events as $event) {
            $highlight = [
                'id' => $event->id ?? null,
                'content' => $event->content ?? '',
                'created_at' => $event->created_at ?? time(),
                'pubkey' => $event->pubkey ?? null,
                'tags' => $event->tags ?? [],
                'article_ref' => null,
                'article_title' => null,
                'article_author' => null,
                'context' => null,
                'url' => null,
                'naddr' => null,
            ];

            $relayHints = [];

            // Extract metadata from tags
            foreach ($event->tags ?? [] as $tag) {
                if (!is_array($tag) || count($tag) < 2) {
                    continue;
                }

                switch ($tag[0]) {
                    case 'a': // Article reference (kind:pubkey:identifier)
                    case 'A':
                        $highlight['article_ref'] = $tag[1] ?? null;
                        // Get relay hint if available
                        if (isset($tag[2]) && str_starts_with($tag[2], 'wss://')) {
                            $relayHints[] = $tag[2];
                        }
                        // Parse to check if it's an article (kind 30023)
                        $parts = explode(':', $tag[1] ?? '', 3);
                        if (count($parts) === 3 && $parts[0] === '30023') {
                            $highlight['article_author'] = $parts[1];
                        }
                        break;
                    case 'context':
                        $highlight['context'] = $tag[1] ?? null;
                        break;
                    case 'r': // URL reference
                        if (!$highlight['url']) {
                            $highlight['url'] = $tag[1] ?? null;
                        }
                        // Also collect relay hints from r tags
                        if (isset($tag[1]) && str_starts_with($tag[1], 'wss://')) {
                            $relayHints[] = $tag[1];
                        }
                        break;
                    case 'title':
                        $highlight['article_title'] = $tag[1] ?? null;
                        break;
                }
            }

            // Only include highlights that reference articles (kind 30023)
            if ($highlight['article_ref'] && str_starts_with($highlight['article_ref'], '30023:')) {
                // Generate naddr from the coordinate
                $highlight['naddr'] = $this->generateNaddr($highlight['article_ref'], $relayHints);
                $processed[] = $highlight;
            }
        }

        // Sort by created_at descending (newest first)
        usort($processed, fn($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $processed;
    }

    /**
     * Generate naddr from coordinate (kind:pubkey:identifier) and relay hints
     */
    private function generateNaddr(string $coordinate, array $relayHints = []): ?string
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            $this->logger->debug('Invalid coordinate format', ['coordinate' => $coordinate]);
            return null;
        }

        try {
            $kind = (int)$parts[0];
            $pubkey = $parts[1];
            $identifier = $parts[2];

            $naddr = Bech32::naddr(
                kind: $kind,
                pubkey: $pubkey,
                identifier: $identifier,
                relays: $relayHints
            );

            return (string)$naddr;

        } catch (\Throwable $e) {
            $this->logger->warning('Failed to generate naddr', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}

