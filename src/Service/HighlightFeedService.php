<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\UserMetadata;
use App\Repository\HighlightRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\Nostr\NostrLinkParser;
use nostriphant\NIP19\Bech32;

class HighlightFeedService
{
    public function __construct(
        private readonly HighlightRepository $highlightRepository,
        private readonly RedisCacheService $redisCacheService,
        private readonly RedisViewStore $viewStore,
        private readonly NostrLinkParser $nostrLinkParser,
    ) {}

    /**
     * @return array{highlights: array<int, array<string, mixed>>, from_redis_view: bool}
     */
    public function loadLatestHighlights(int $limit = 200): array
    {
        $cachedView = $this->viewStore->fetchLatestHighlights();

        if ($cachedView !== null) {
            $highlights = [];

            foreach ($cachedView as $baseObject) {
                if (!isset($baseObject['highlight'])) {
                    continue;
                }

                $article = $baseObject['article'] ?? null;

                $articleCoordinate = null;
                if ($article && isset($article['kind'], $article['pubkey'], $article['slug'])) {
                    $articleCoordinate = $article['kind'] . ':' . $article['pubkey'] . ':' . $article['slug'];
                } elseif (isset($baseObject['highlight']['refs']['article_coordinate'])) {
                    $articleCoordinate = $baseObject['highlight']['refs']['article_coordinate'];
                }

                $naddr = $articleCoordinate ? $this->generateNaddr($articleCoordinate, []) : null;
                $eventRef = $baseObject['highlight']['refs']['event_ref'] ?? null;

                $highlights[] = [
                    'id' => $baseObject['highlight']['eventId'] ?? null,
                    'event_ref' => $eventRef,
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
                    'preview' => $naddr ? $this->createPreviewData($naddr) : null,
                    'profile' => $baseObject['author'] ?? null,
                    'article_author_profile' => $baseObject['profiles'][$article['pubkey'] ?? ''] ?? null,
                ];
            }

            return [
                'highlights' => $highlights,
                'from_redis_view' => true,
            ];
        }

        $highlightsWithArticles = $this->highlightRepository->findLatestWithArticles($limit);
        $highlights = $this->buildHighlightsFromDatabaseRows($highlightsWithArticles);

        return [
            'highlights' => $highlights,
            'from_redis_view' => false,
        ];
    }

    /**
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
            $eventRef = $this->extractEventRef($highlightEntity->getRawEvent());

            $highlights[] = [
                'id' => $highlightEntity->getEventId(),
                'event_ref' => $eventRef,
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

    private function generateNaddr(string $coordinate, array $relayHints = []): ?string
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            return (string) Bech32::naddr(
                kind: (int) $parts[0],
                pubkey: $parts[1],
                identifier: $parts[2],
                relays: $relayHints,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function createPreviewData(string $naddr): ?array
    {
        try {
            $links = $this->nostrLinkParser->parseLinks("nostr:$naddr");

            return !empty($links) ? $links[0] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extract event reference from raw event 'e' tags
     */
    private function extractEventRef(?array $rawEvent): ?string
    {
        if (!$rawEvent || !isset($rawEvent['tags'])) {
            return null;
        }

        foreach ($rawEvent['tags'] as $tag) {
            if (is_array($tag) && count($tag) >= 2 && in_array($tag[0], ['e', 'E'])) {
                return $tag[1] ?? null;
            }
        }

        return null;
    }
}
