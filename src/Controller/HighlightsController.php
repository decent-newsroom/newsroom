<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Service\HighlightService;
use App\Service\NostrClient;
use App\Service\NostrLinkParser;
use App\Service\RedisViewStore;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly HighlightService $highlightService,
        private readonly LoggerInterface $logger,
        private readonly NostrLinkParser $nostrLinkParser,
        private readonly RedisViewStore $viewStore,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/highlights', name: 'highlights')]
    public function index(CacheInterface $cache): Response
    {
        try {
            // Fast path: Try Redis views first (single GET)
            $cachedView = $this->viewStore->fetchLatestHighlights();

            if ($cachedView !== null) {
                // Use Redis view - extract highlights data
                $highlights = [];

                foreach ($cachedView as $baseObject) {
                    if (isset($baseObject['highlight']) && isset($baseObject['article'])) {
                        // Transform Redis view format to legacy highlight format
                        $highlight = [
                            'id' => $baseObject['highlight']['eventId'] ?? null,
                            'content' => $baseObject['highlight']['content'] ?? '',
                            'created_at' => isset($baseObject['highlight']['createdAt'])
                                ? strtotime($baseObject['highlight']['createdAt'])
                                : time(),
                            'pubkey' => $baseObject['highlight']['pubkey'] ?? null,
                            'context' => $baseObject['highlight']['context'] ?? null,
                            'article_ref' => $baseObject['article']['eventId'] ?? null,
                            'article_title' => $baseObject['article']['title'] ?? null,
                            'article_author' => $baseObject['article']['pubkey'] ?? null,
                            'article_slug' => $baseObject['article']['slug'] ?? null,
                            'profile' => $baseObject['author'] ?? null, // Highlight author profile
                            'article_author_profile' => $baseObject['profiles'][$baseObject['article']['pubkey']] ?? null,
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

            // Fallback path: Use old cache system if Redis view not available
            $this->logger->debug('Redis view not found, falling back to Nostr relay fetch');

            // Cache key for highlights
            $cacheKey = 'global_article_highlights';
            // Get highlights from cache or fetch fresh
            $highlights = $cache->get($cacheKey, function (ItemInterface $item) {
                $item->expiresAfter(self::CACHE_TTL);

                try {
                    // Fetch highlights that reference articles (kind 30023)
                    $events = $this->nostrClient->getArticleHighlights(self::MAX_DISPLAY_HIGHLIGHTS);

                    // Save highlights to database as Event entities
                    $this->saveHighlightsAsEvents($events);

                    // Process and enrich the highlights for display
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
     * Save highlights as Event entities to database
     */
    private function saveHighlightsAsEvents(array $events): void
    {
        $saved = 0;
        $skipped = 0;

        foreach ($events as $nostrEvent) {
            try {
                // Skip if event ID is missing
                if (!isset($nostrEvent->id) || empty($nostrEvent->id)) {
                    $this->logger->warning('Skipping event without ID');
                    $skipped++;
                    continue;
                }

                // Check if event already exists
                $existingEvent = $this->entityManager->getRepository(Event::class)->find($nostrEvent->id);
                if ($existingEvent) {
                    $this->logger->debug('Event already exists, skipping', ['event_id' => $nostrEvent->id]);
                    $skipped++;
                    continue;
                }

                // Create new Event entity
                $event = new Event();
                $event->setId($nostrEvent->id);
                $event->setEventId($nostrEvent->id);
                $event->setKind($nostrEvent->kind ?? 9802);
                $event->setPubkey($nostrEvent->pubkey ?? '');
                $event->setContent($nostrEvent->content ?? '');
                $event->setCreatedAt($nostrEvent->created_at ?? time());
                $event->setTags($nostrEvent->tags ?? []);
                $event->setSig($nostrEvent->sig ?? '');

                $this->entityManager->persist($event);
                $saved++;

                $this->logger->debug('Saved highlight as Event entity', [
                    'event_id' => $nostrEvent->id,
                    'kind' => $nostrEvent->kind,
                    'pubkey' => substr($nostrEvent->pubkey ?? '', 0, 16),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to save highlight as Event entity', [
                    'event_id' => $nostrEvent->id ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                $skipped++;
            }
        }

        try {
            $this->entityManager->flush();
            $this->logger->info('Saved highlights as Event entities', [
                'saved' => $saved,
                'skipped' => $skipped,
                'total' => count($events)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to flush Event entities', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Save highlights to database grouped by article coordinate
     */
    private function saveHighlightsToDatabase(array $events): void
    {
        // Group events by article coordinate
        $eventsByArticle = [];

        foreach ($events as $event) {
            // Extract article coordinate from tags
            foreach ($event->tags ?? [] as $tag) {
                if (!is_array($tag) || count($tag) < 2) {
                    continue;
                }

                if (in_array($tag[0], ['a', 'A'])) {
                    $coordinate = $tag[1] ?? null;
                    if ($coordinate && str_starts_with($coordinate, '30023:')) {
                        if (!isset($eventsByArticle[$coordinate])) {
                            $eventsByArticle[$coordinate] = [];
                        }
                        $eventsByArticle[$coordinate][] = $event;
                        break;
                    }
                }
            }
        }

        // Save each article's highlights to database
        foreach ($eventsByArticle as $coordinate => $articleEvents) {
            try {
                $this->highlightService->saveEventsToDatabase($coordinate, $articleEvents);

                $this->logger->debug('Saved highlights to database', [
                    'coordinate' => $coordinate,
                    'count' => count($articleEvents)
                ]);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to save highlights to database', [
                    'coordinate' => $coordinate,
                    'error' => $e->getMessage()
                ]);
            }
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

                // Parse naddr to create preview data for NostrPreview component
                if ($highlight['naddr']) {
                    $highlight['preview'] = $this->createPreviewData($highlight['naddr']);
                }

                $processed[] = $highlight;
            }
        }

        // Sort & dedupe by article_ref
        usort($processed, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        $uniqueHighlights = [];
        foreach ($processed as $highlight) {
            $ref = $highlight['article_ref'];
            if (!isset($uniqueHighlights[$ref])) {
                $uniqueHighlights[$ref] = $highlight;
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

