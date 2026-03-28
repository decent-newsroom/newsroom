<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Message\FetchHighlightsMessage;
use App\Repository\EventRepository;
use App\Service\Cache\RedisViewStore;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrLinkParser;
use nostriphant\NIP19\Bech32;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class HighlightsController extends AbstractController
{
    private const MAX_DISPLAY_HIGHLIGHTS = 200;

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
        private readonly NostrLinkParser $nostrLinkParser,
        private readonly RedisViewStore $viewStore,
        private readonly MessageBusInterface $messageBus,
    ) {}

    #[Route('/highlights', name: 'highlights')]
    public function index(): Response
    {
        try {
            // Fast path: Try Redis views first (single GET)
            $cachedView = $this->viewStore->fetchLatestHighlights();

            if ($cachedView !== null) {
                // Use Redis view - extract highlights data
                $highlights = [];

                foreach ($cachedView as $baseObject) {
                    if (isset($baseObject['highlight'])) {
                        $article = $baseObject['article'] ?? null;

                        // Build article coordinate: prefer article data, fall back to highlight refs
                        $articleCoordinate = null;
                        if ($article && isset($article['kind']) && isset($article['pubkey']) && isset($article['slug'])) {
                            $articleCoordinate = $article['kind'] . ':' . $article['pubkey'] . ':' . $article['slug'];
                        } elseif (isset($baseObject['highlight']['refs']['article_coordinate'])) {
                            $articleCoordinate = $baseObject['highlight']['refs']['article_coordinate'];
                        }

                        // Generate naddr and preview data for NostrPreview component
                        $naddr = null;
                        $preview = null;
                        if ($articleCoordinate) {
                            $naddr = $this->generateNaddr($articleCoordinate, []);
                            if ($naddr) {
                                $preview = $this->createPreviewData($naddr);
                            }
                        }

                        // Transform Redis view format to legacy highlight format
                        $highlight = [
                            'id' => $baseObject['highlight']['eventId'] ?? null,
                            'content' => $baseObject['highlight']['content'] ?? '',
                            'created_at' => isset($baseObject['highlight']['createdAt'])
                                ? strtotime($baseObject['highlight']['createdAt'])
                                : time(),
                            'pubkey' => $baseObject['highlight']['pubkey'] ?? null,
                            'context' => $baseObject['highlight']['context'] ?? null,
                            'article_ref' => $articleCoordinate,
                            'article_title' => ($article && !empty($article['title'])) ? $article['title'] : null,
                            'article_author' => $article['pubkey'] ?? null,
                            'article_slug' => $article['slug'] ?? null,
                            'naddr' => $naddr,
                            'preview' => $preview,
                            'profile' => $baseObject['author'] ?? null, // Highlight author profile
                            'article_author_profile' => $baseObject['profiles'][$article['pubkey'] ?? ''] ?? null,
                        ];

                        $highlights[] = $highlight;
                    }
                }

                $this->logger->info('Loaded highlights from Redis view', ['count' => count($highlights)]);

                return $this->render('pages/highlights.html.twig', [
                    'highlights' => $highlights,
                    'total' => count($highlights),
                    'from_redis_view' => true,
                ]);
            }

            // Fallback path: Load highlights from database
            $this->logger->debug('Redis view not found, loading from database');

            // Query highlights from database
            $highlightEvents = $this->eventRepository->findHighlights(self::MAX_DISPLAY_HIGHLIGHTS);

            // Dispatch async message to fetch fresh highlights in background (fire and forget)
            try {
                $this->messageBus->dispatch(new FetchHighlightsMessage(self::MAX_DISPLAY_HIGHLIGHTS));
                $this->logger->debug('Dispatched async fetch for highlights');
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to dispatch highlights fetch', ['error' => $e->getMessage()]);
            }

            // Process and enrich the highlights for display
            $highlights = $this->processHighlights($highlightEvents);

            return $this->render('pages/highlights.html.twig', [
                'highlights' => $highlights,
                'total' => count($highlights),
                'from_redis_view' => false,
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
        $pubkeys = [];

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
                'profile' => null,
            ];

            if ($highlight['pubkey']) {
                $pubkeys[] = $highlight['pubkey'];
            }

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
                        // Extract article author from coordinate
                        $parts = explode(':', $tag[1] ?? '', 3);
                        if (count($parts) === 3) {
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

            // Include highlights that reference addressable events (articles, publications, etc.)
            if ($highlight['article_ref']) {
                $refParts = explode(':', $highlight['article_ref'], 3);
                $refKind = $refParts[0] ?? null;

                // Generate naddr for addressable event kinds (30xxx)
                if ($refKind && str_starts_with($refKind, '30')) {
                    $highlight['naddr'] = $this->generateNaddr($highlight['article_ref'], $relayHints);

                    if ($highlight['naddr']) {
                        $highlight['preview'] = $this->createPreviewData($highlight['naddr']);
                    }
                }

                $processed[] = $highlight;
            }
        }

        // Sort & dedupe by article_ref + highlight author (one highlight per author per article)
        usort($processed, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        $uniqueHighlights = [];
        foreach ($processed as $highlight) {
            $dedupeKey = ($highlight['article_ref'] ?? '') . '|' . ($highlight['pubkey'] ?? '');
            if (!isset($uniqueHighlights[$dedupeKey])) {
                $uniqueHighlights[$dedupeKey] = $highlight;
            }
        }

        // Batch fetch metadata for all pubkeys (local relay first, fallback to reputable relays for missing)
        $uniquePubkeys = array_values(array_unique(array_filter($pubkeys, fn($p) => is_string($p) && strlen($p) === 64)));
        if (!empty($uniquePubkeys)) {
            try {
                $metadataMap = $this->nostrClient->getMetadataForPubkeys($uniquePubkeys, true); // map pubkey => event
                foreach ($uniqueHighlights as &$highlight) {
                    $pubkey = $highlight['pubkey'];
                    if ($pubkey && isset($metadataMap[$pubkey])) {
                        $metaEvent = $metadataMap[$pubkey];
                        // Parse metadata JSON content
                        $profile = null;
                        if (isset($metaEvent->content)) {
                            $decoded = json_decode($metaEvent->content, true);
                            if (is_array($decoded)) {
                                $profile = [
                                    'name' => $decoded['name'] ?? ($decoded['display_name'] ?? null),
                                    'display_name' => $decoded['display_name'] ?? null,
                                    'picture' => $decoded['picture'] ?? null,
                                    'banner' => $decoded['banner'] ?? null,
                                    'about' => $decoded['about'] ?? null,
                                    'website' => $decoded['website'] ?? null,
                                    'nip05' => $decoded['nip05'] ?? null,
                                ];
                            }
                        }
                        $highlight['profile'] = $profile;
                    }
                }
                unset($highlight);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed batch metadata enrichment for highlights', [
                    'error' => $e->getMessage(),
                    'pubkeys_count' => count($uniquePubkeys)
                ]);
            }
        }

        return $uniqueHighlights;
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

    /**
     * Create preview data structure for NostrPreview component
     */
    private function createPreviewData(string $naddr): ?array
    {
        try {
            // Use NostrLinkParser to parse the naddr identifier
            $links = $this->nostrLinkParser->parseLinks("nostr:$naddr");

            if (!empty($links)) {
                return $links[0];
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->debug('Failed to create preview data', [
                'naddr' => $naddr,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}

