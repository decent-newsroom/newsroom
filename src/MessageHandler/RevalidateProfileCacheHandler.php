<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\AuthorContentType;
use App\Enum\KindsEnum;
use App\Message\FetchAuthorContentMessage;
use App\Message\RevalidateProfileCacheMessage;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Service\AuthorRelayService;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\Search\ArticleSearchInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handler for revalidating profile cache in the background.
 * Part of stale-while-revalidate pattern for instant profile loads.
 */
#[AsMessageHandler]
class RevalidateProfileCacheHandler
{
    public function __construct(
        private readonly RedisViewStore $viewStore,
        private readonly RedisViewFactory $viewFactory,
        private readonly RedisCacheService $redisCacheService,
        private readonly ArticleSearchInterface $articleSearch,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $messageBus,
        private readonly AuthorRelayService $authorRelayService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RevalidateProfileCacheMessage $message): void
    {
        $pubkey = $message->getPubkey();
        $tab = $message->getTab();
        $isOwner = $message->isOwner();

        $this->logger->info('🔄 Revalidating profile cache', [
            'pubkey' => substr($pubkey, 0, 8),
            'tab' => $tab,
            'isOwner' => $isOwner,
        ]);

        try {
            // Dispatch async fetch to get fresh data from relays
            $relays = $this->authorRelayService->getRelaysForFetching($pubkey);
            $this->messageBus->dispatch(new FetchAuthorContentMessage(
                $pubkey,
                $isOwner ? AuthorContentType::cases() : AuthorContentType::publicTypes(),
                0,
                $isOwner,
                $relays
            ));

            // Rebuild cache based on tab
            $data = match($tab) {
                'overview' => $this->buildOverviewData($pubkey, $isOwner),
                'articles' => $this->buildArticlesData($pubkey, $isOwner),
                'media' => $this->buildMediaData($pubkey),
                'highlights' => $this->buildHighlightsData($pubkey),
                'drafts' => $isOwner ? $this->buildDraftsData($pubkey) : [],
                default => [],
            };

            // Store updated cache
            $this->viewStore->storeProfileTabData($pubkey, $tab, $data);

            $this->logger->info('✅ Profile cache revalidated', [
                'pubkey' => substr($pubkey, 0, 8),
                'tab' => $tab,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('❌ Failed to revalidate profile cache', [
                'pubkey' => substr($pubkey, 0, 8),
                'tab' => $tab,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildOverviewData(string $pubkey, bool $isOwner): array
    {
        // Get recent articles
        $allArticles = $this->buildArticlesData($pubkey, false);
        $recentArticles = array_slice($allArticles['articles'] ?? [], 0, 3);

        // Get recent media
        $mediaData = $this->buildMediaData($pubkey);
        $recentMedia = array_slice($mediaData['mediaEvents'] ?? [], 0, 6);

        // Get recent highlights
        $highlightsData = $this->buildHighlightsData($pubkey);
        $recentHighlights = array_slice($highlightsData['highlights'] ?? [], 0, 3);

        return [
            'recentArticles' => $recentArticles,
            'recentMedia' => $recentMedia,
            'recentHighlights' => $recentHighlights,
        ];
    }

    private function buildArticlesData(string $pubkey, bool $isOwner): array
    {
        $cachedArticles = $this->viewStore->fetchUserArticles($pubkey);
        $viewData = [];

        if ($cachedArticles !== null) {
            foreach ($cachedArticles as $baseObject) {
                if (isset($baseObject['article'])) {
                    $articleData = $baseObject['article'];
                    $kind = $articleData['kind'] ?? null;
                    $slug = $articleData['slug'] ?? null;

                    // Skip drafts
                    if ($kind === KindsEnum::LONGFORM_DRAFT->value) {
                        continue;
                    }

                    if ($slug) {
                        $viewData[] = $articleData;
                    }
                }
            }
        } else {
            // Fallback to database
            $articles = $this->articleSearch->findByPubkey($pubkey, 100, 0);
            $authorMetadata = $this->redisCacheService->getMetadata($pubkey);

            foreach ($articles as $article) {
                if ($article instanceof Article) {
                    // Skip drafts unless owner
                    if (!$isOwner && $article->getKind() === KindsEnum::LONGFORM_DRAFT) {
                        continue;
                    }

                    try {
                        $baseObject = $this->viewFactory->articleBaseObject($article, $authorMetadata);
                        $normalized = $this->viewFactory->normalizeBaseObject($baseObject);
                        if (isset($normalized['article'])) {
                            $viewData[] = $normalized['article'];
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to build article view', ['error' => $e->getMessage()]);
                    }
                }
            }
        }

        // Deduplicate by slug
        $slugMap = [];
        foreach ($viewData as $article) {
            $slug = $article['slug'] ?? null;
            $createdAt = $article['createdAt'] ?? 0;
            if ($slug && (!isset($slugMap[$slug]) || $createdAt > ($slugMap[$slug]['createdAt'] ?? 0))) {
                $slugMap[$slug] = $article;
            }
        }

        return ['articles' => array_values($slugMap)];
    }

    private function buildMediaData(string $pubkey): array
    {
        $paginatedData = $this->redisCacheService->getMediaEventsPaginated($pubkey, 1, 24);
        $mediaEvents = [];

        foreach ($paginatedData['events'] as $event) {
            $mediaEvents[] = [
                'id' => $event->id ?? null,
                'pubkey' => $event->pubkey ?? null,
                'created_at' => $event->created_at ?? null,
                'kind' => $event->kind ?? null,
                'tags' => $event->tags ?? [],
                'content' => $event->content ?? '',
            ];
        }

        return [
            'mediaEvents' => $mediaEvents,
            'hasMore' => $paginatedData['hasMore'],
            'total' => $paginatedData['total'],
        ];
    }

    private function buildHighlightsData(string $pubkey): array
    {
        $repo = $this->em->getRepository(Event::class);
        $events = $repo->findBy(
            ['pubkey' => $pubkey, 'kind' => KindsEnum::HIGHLIGHTS->value],
            ['created_at' => 'DESC'],
            50
        );

        $highlights = [];
        foreach ($events as $event) {
            $context = null;
            $sourceUrl = null;

            foreach ($event->getTags() as $tag) {
                if (!is_array($tag) || count($tag) < 2) {
                    continue;
                }

                if ($tag[0] === 'context') {
                    $context = $tag[1] ?? null;
                } elseif ($tag[0] === 'r' && !$sourceUrl) {
                    $sourceUrl = $tag[1] ?? null;
                }
            }

            $highlights[] = [
                'id' => $event->getId(),
                'content' => $event->getContent(),
                'context' => $context,
                'sourceUrl' => $sourceUrl,
                'createdAt' => $event->getCreatedAt(),
            ];
        }

        return ['highlights' => $highlights];
    }

    private function buildDraftsData(string $pubkey): array
    {
        $allArticles = $this->articleSearch->findByPubkey($pubkey, 100, 0);
        $authorMetadata = $this->redisCacheService->getMetadata($pubkey);
        $drafts = [];

        foreach ($allArticles as $article) {
            if ($article instanceof Article && $article->getKind() === KindsEnum::LONGFORM_DRAFT) {
                try {
                    $baseObject = $this->viewFactory->articleBaseObject($article, $authorMetadata);
                    $normalized = $this->viewFactory->normalizeBaseObject($baseObject);
                    if (isset($normalized['article'])) {
                        $drafts[] = $normalized['article'];
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to build draft view', ['error' => $e->getMessage()]);
                }
            }
        }

        // Deduplicate by slug
        $slugMap = [];
        foreach ($drafts as $draft) {
            $slug = $draft['slug'] ?? null;
            $createdAt = $draft['createdAt'] ?? 0;
            if ($slug && (!isset($slugMap[$slug]) || $createdAt > ($slugMap[$slug]['createdAt'] ?? 0))) {
                $slugMap[$slug] = $draft;
            }
        }

        return ['drafts' => array_values($slugMap)];
    }
}

