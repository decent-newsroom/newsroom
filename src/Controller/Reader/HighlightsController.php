<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Dto\UserMetadata;
use App\Message\FetchHighlightsMessage;
use App\Repository\HighlightRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
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
        private readonly HighlightRepository $highlightRepository,
        private readonly RedisCacheService $redisCacheService,
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
            $this->logger->debug('Redis view not found, loading highlights from Highlight repository');

            // Keep fallback source aligned with app:cache-latest-highlights.
            $highlightsWithArticles = $this->highlightRepository->findLatestWithArticles(self::MAX_DISPLAY_HIGHLIGHTS);

            // Dispatch async message to fetch fresh highlights in background (fire and forget)
            try {
                $this->messageBus->dispatch(new FetchHighlightsMessage(self::MAX_DISPLAY_HIGHLIGHTS));
                $this->logger->debug('Dispatched async fetch for highlights');
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to dispatch highlights fetch', ['error' => $e->getMessage()]);
            }

            $highlights = $this->buildHighlightsFromDatabaseRows($highlightsWithArticles);

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
     * Build highlight payloads from HighlightRepository::findLatestWithArticles rows.
     *
     * @param array<array{highlight: \App\Entity\Highlight, article: ?\App\Entity\Article}> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildHighlightsFromDatabaseRows(array $rows): array
    {
        $pubkeys = [];
        foreach ($rows as $item) {
            $highlight = $item['highlight'];
            $article = $item['article'];

            if ($highlight->getPubkey()) {
                $pubkeys[] = $highlight->getPubkey();
            }

            if ($article && $article->getPubkey()) {
                $pubkeys[] = $article->getPubkey();
            }
        }

        $metadataMap = [];
        $uniquePubkeys = array_values(array_unique(array_filter($pubkeys)));
        if ($uniquePubkeys !== []) {
            $metadataMap = $this->redisCacheService->getMultipleMetadata($uniquePubkeys);
        }

        $highlights = [];

        foreach ($rows as $item) {
            $highlightEntity = $item['highlight'];
            $articleEntity = $item['article'];
            $articleCoordinate = $highlightEntity->getArticleCoordinate();

            if (!$articleCoordinate) {
                continue;
            }

            $articleKind = null;
            $articlePubkey = $articleEntity?->getPubkey();
            $articleSlug = $articleEntity?->getSlug();

            if (!$articleEntity) {
                $parts = explode(':', $articleCoordinate, 3);
                if (count($parts) === 3) {
                    $articleKind = (int) $parts[0];
                    $articlePubkey = $articlePubkey ?: $parts[1];
                    $articleSlug = $articleSlug ?: $parts[2];
                }
            } else {
                $articleKind = $articleEntity->getKind()->value;
            }

            $naddr = $this->generateNaddr($articleCoordinate, []);

            $highlights[] = [
                'id' => $highlightEntity->getEventId(),
                'content' => $highlightEntity->getContent() ?? '',
                'created_at' => $highlightEntity->getCreatedAt() ?? time(),
                'pubkey' => $highlightEntity->getPubkey(),
                'context' => $highlightEntity->getContext(),
                'article_ref' => $articleCoordinate,
                'article_title' => $articleEntity?->getTitle(),
                'article_author' => $articlePubkey,
                'article_slug' => $articleSlug,
                'article_kind' => $articleKind,
                'naddr' => $naddr,
                'preview' => $naddr ? $this->createPreviewData($naddr) : null,
                'profile' => $this->metadataToProfile($metadataMap[$highlightEntity->getPubkey()] ?? null),
                'article_author_profile' => $articlePubkey
                    ? $this->metadataToProfile($metadataMap[$articlePubkey] ?? null)
                    : null,
            ];
        }

        return $highlights;
    }

    private function metadataToProfile(mixed $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        if ($metadata instanceof UserMetadata) {
            return [
                'name' => $metadata->name,
                'display_name' => $metadata->displayName,
                'picture' => $metadata->picture,
                'banner' => $metadata->banner,
                'about' => $metadata->about,
                'website' => $metadata->getWebsite(),
                'nip05' => $metadata->getNip05(),
            ];
        }

        if ($metadata instanceof \stdClass) {
            $metadata = (array) $metadata;
        }

        if (!is_array($metadata)) {
            return null;
        }

        $readMaybeArray = static function (mixed $value): ?string {
            if (is_array($value)) {
                return isset($value[0]) ? (string) $value[0] : null;
            }
            return is_string($value) ? $value : null;
        };

        return [
            'name' => $metadata['name'] ?? ($metadata['display_name'] ?? null),
            'display_name' => $metadata['display_name'] ?? null,
            'picture' => $metadata['picture'] ?? null,
            'banner' => $metadata['banner'] ?? null,
            'about' => $metadata['about'] ?? null,
            'website' => $readMaybeArray($metadata['website'] ?? null),
            'nip05' => $readMaybeArray($metadata['nip05'] ?? null),
        ];
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

