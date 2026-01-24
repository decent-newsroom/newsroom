<?php

namespace App\ReadModel\RedisView;

use App\Entity\Article;
use App\Entity\Event;
use App\Entity\Highlight;
use App\Enum\KindsEnum;
use App\Service\Cache\RedisCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Factory service for converting entities into Redis view objects
 * Handles normalization/denormalization for Redis storage
 */
class RedisViewFactory
{
    public function __construct(
        private readonly RedisCacheService $redisCacheService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Convert Nostr kind 0 profile metadata to RedisProfileView
     * @param array|\stdClass|\App\Dto\UserMetadata|null $metadata Profile metadata (from RedisCacheService)
     */
    public function profileToView(array|\stdClass|\App\Dto\UserMetadata|null $metadata, string $pubkey): ?RedisProfileView
    {
        if (empty($metadata)) {
            $this->logger->debug('No metadata found for pubkey', ['pubkey' => $pubkey]);
            return null;
        }

        // Handle UserMetadata DTO (new format)
        if ($metadata instanceof \App\Dto\UserMetadata) {
            return new RedisProfileView(
                pubkey: $pubkey,
                name: $metadata->name,
                display_name: $metadata->displayName,
                picture: $metadata->picture,
                nip05: $metadata->getNip05(),  // Get first element from array
                about: $metadata->about,
                website: $metadata->getWebsite(),  // Get first element from array
                lud16: $metadata->getLightningAddress(),  // Get first lud16 or lud06
                banner: $metadata->banner,
            );
        }

        // Convert stdClass to array if needed (legacy format)
        if ($metadata instanceof \stdClass) {
            $metadata = json_decode(json_encode($metadata), true) ?? [];
        }

        // Helper to extract string from array or return string/null (legacy format)
        $getString = function($value): ?string {
            if (is_array($value)) {
                return !empty($value) ? (string)$value[0] : null;
            }
            return $value ? (string)$value : null;
        };

        return new RedisProfileView(
            pubkey: $pubkey,
            name: $metadata['name'] ?? $metadata['display_name'] ?? null,        // PRIMARY name field
            display_name: $metadata['display_name'] ?? null,
            picture: $metadata['picture'] ?? null,                                // Match template expectation
            nip05: $getString($metadata['nip05'] ?? null),
            about: $metadata['about'] ?? null,
            website: $metadata['website'] ?? null,
            lud16: $getString($metadata['lud16'] ?? null),
            banner: $metadata['banner'] ?? null,
        );
    }

    /**
     * Convert Article entity to RedisArticleView
     */
    public function articleToView(Article $article): RedisArticleView
    {
        return new RedisArticleView(
            id: (string) $article->getId(),
            slug: $article->getSlug() ?? '',                // Template expects: article.slug
            title: $article->getTitle() ?? '',              // Template expects: article.title
            pubkey: $article->getPubkey() ?? '',            // Template expects: article.pubkey
            createdAt: $article->getCreatedAt(),            // Template expects: article.createdAt
            summary: $article->getSummary(),                // Template expects: article.summary
            image: $article->getImage(),                    // Template expects: article.image
            eventId: $article->getEventId() ?? '',
            contentHtml: $article->getProcessedHtml(),
            publishedAt: $article->getPublishedAt(),
            topics: $article->getTopics() ?? [],
            kind: $article->getKind()->value,
        );
    }

    /**
     * Convert Highlight entity to RedisHighlightView
     */
    public function highlightToView(Highlight $highlight): RedisHighlightView
    {
        // Convert Unix timestamp to DateTimeImmutable
        $createdAt = $highlight->getCreatedAt();
        $createdAtDt = $createdAt instanceof \DateTimeImmutable
            ? $createdAt
            : new \DateTimeImmutable('@' . $createdAt);

        return new RedisHighlightView(
            eventId: $highlight->getEventId() ?? '',
            pubkey: $highlight->getPubkey() ?? '',
            createdAt: $createdAtDt,
            content: $highlight->getContent(),
            context: $highlight->getContext(),
            refs: [
                'article_coordinate' => $highlight->getArticleCoordinate(),
            ],
        );
    }

    /**
     * Build a complete RedisBaseObject for an article
     * Fetches author profile from Redis metadata cache
     * @param Article $article
     * @param array|\stdClass|\App\Dto\UserMetadata|null $authorMetadata Author profile metadata (from RedisCacheService)
     */
    public function articleBaseObject(Article $article, array|\stdClass|\App\Dto\UserMetadata|null $authorMetadata = null): RedisBaseObject
    {
        $articleView = $this->articleToView($article);

        // Fetch author metadata if not provided
        if ($authorMetadata === null) {
            $authorMetadata = $this->redisCacheService->getMetadata($article->getPubkey());
        }

        $authorView = $this->profileToView($authorMetadata, $article->getPubkey());

        $profiles = [];
        if ($authorView !== null) {
            $profiles[$authorView->pubkey] = $authorView;
        }

        return new RedisBaseObject(
            article: $articleView,
            highlight: null,
            author: $authorView,
            profiles: $profiles,
            meta: [],
        );
    }

    /**
     * Build a complete RedisBaseObject for a highlight
     * Requires the highlighted article and fetches both author profiles
     * @param Highlight $highlight
     * @param Article $article
     * @param array|\stdClass|\App\Dto\UserMetadata|null $highlightAuthorMetadata Highlight author metadata
     * @param array|\stdClass|\App\Dto\UserMetadata|null $articleAuthorMetadata Article author metadata
     */
    public function highlightBaseObject(
        Highlight $highlight,
        Article $article,
        array|\stdClass|\App\Dto\UserMetadata|null $highlightAuthorMetadata = null,
        array|\stdClass|\App\Dto\UserMetadata|null $articleAuthorMetadata = null
    ): RedisBaseObject {
        $articleView = $this->articleToView($article);
        $highlightView = $this->highlightToView($highlight);

        // Fetch metadata if not provided
        if ($highlightAuthorMetadata === null) {
            $highlightAuthorMetadata = $this->redisCacheService->getMetadata($highlight->getPubkey());
        }
        if ($articleAuthorMetadata === null) {
            $articleAuthorMetadata = $this->redisCacheService->getMetadata($article->getPubkey());
        }

        $highlightAuthorView = $this->profileToView($highlightAuthorMetadata, $highlight->getPubkey());
        $articleAuthorView = $this->profileToView($articleAuthorMetadata, $article->getPubkey());

        $profiles = [];
        if ($articleAuthorView !== null) {
            $profiles[$articleAuthorView->pubkey] = $articleAuthorView;
        }
        if ($highlightAuthorView !== null) {
            $profiles[$highlightAuthorView->pubkey] = $highlightAuthorView;
        }

        return new RedisBaseObject(
            article: $articleView,
            highlight: $highlightView,
            author: $highlightAuthorView, // Primary author is the highlight author
            profiles: $profiles,
            meta: [],
        );
    }

    /**
     * Normalize RedisBaseObject to array for JSON storage
     */
    public function normalizeBaseObject(RedisBaseObject $obj): array
    {
        return [
            'article' => $obj->article ? $this->normalizeArticleView($obj->article) : null,
            'highlight' => $obj->highlight ? $this->normalizeHighlightView($obj->highlight) : null,
            'author' => $obj->author ? $this->normalizeProfileView($obj->author) : null,
            'profiles' => array_map(
                fn(RedisProfileView $profile) => $this->normalizeProfileView($profile),
                $obj->profiles
            ),
            'meta' => $obj->meta,
        ];
    }

    /**
     * Denormalize array back to RedisBaseObject
     */
    public function denormalizeBaseObject(array $data): RedisBaseObject
    {
        $profiles = [];
        foreach ($data['profiles'] ?? [] as $pubkey => $profileData) {
            $profiles[$pubkey] = $this->denormalizeProfileView($profileData);
        }

        return new RedisBaseObject(
            article: isset($data['article']) ? $this->denormalizeArticleView($data['article']) : null,
            highlight: isset($data['highlight']) ? $this->denormalizeHighlightView($data['highlight']) : null,
            author: isset($data['author']) ? $this->denormalizeProfileView($data['author']) : null,
            profiles: $profiles,
            meta: $data['meta'] ?? [],
        );
    }

    private function normalizeProfileView(RedisProfileView $view): array
    {
        return [
            'pubkey' => $view->pubkey,
            'name' => $view->name,
            'display_name' => $view->display_name,
            'picture' => $view->picture,
            'nip05' => $view->nip05,
            'about' => $view->about,
            'website' => $view->website,
            'lud16' => $view->lud16,
            'banner' => $view->banner,
        ];
    }

    private function denormalizeProfileView(array $data): RedisProfileView
    {
        return new RedisProfileView(
            pubkey: $data['pubkey'],
            name: $data['name'] ?? null,
            display_name: $data['display_name'] ?? null,
            picture: $data['picture'] ?? null,
            nip05: $data['nip05'] ?? null,
            about: $data['about'] ?? null,
            website: $data['website'] ?? null,
            lud16: $data['lud16'] ?? null,
            banner: $data['banner'] ?? null,
        );
    }

    private function normalizeArticleView(RedisArticleView $view): array
    {
        return [
            'id' => $view->id,
            'slug' => $view->slug,
            'title' => $view->title,
            'pubkey' => $view->pubkey,
            'createdAt' => $view->createdAt?->format(\DateTimeInterface::ATOM),
            'summary' => $view->summary,
            'image' => $view->image,
            'eventId' => $view->eventId,
            'contentHtml' => $view->contentHtml,
            'publishedAt' => $view->publishedAt?->format(\DateTimeInterface::ATOM),
            'topics' => $view->topics,
            'kind' => $view->kind, // Add kind to normalization
        ];
    }

    private function denormalizeArticleView(array $data): RedisArticleView
    {
        return new RedisArticleView(
            id: $data['id'],
            slug: $data['slug'],
            title: $data['title'],
            pubkey: $data['pubkey'],
            createdAt: isset($data['createdAt']) ? new \DateTimeImmutable($data['createdAt']) : null,
            summary: $data['summary'] ?? null,
            image: $data['image'] ?? null,
            eventId: $data['eventId'] ?? null,
            contentHtml: $data['contentHtml'] ?? null,
            publishedAt: isset($data['publishedAt']) ? new \DateTimeImmutable($data['publishedAt']) : null,
            topics: $data['topics'] ?? [],
            kind: $data['kind'] ?? null // Add kind to denormalization
        );
    }

    private function normalizeHighlightView(RedisHighlightView $view): array
    {
        return [
            'eventId' => $view->eventId,
            'pubkey' => $view->pubkey,
            'createdAt' => $view->createdAt->format(\DateTimeInterface::ATOM),
            'content' => $view->content,
            'context' => $view->context,
            'refs' => $view->refs,
        ];
    }

    private function denormalizeHighlightView(array $data): RedisHighlightView
    {
        return new RedisHighlightView(
            eventId: $data['eventId'],
            pubkey: $data['pubkey'],
            createdAt: new \DateTimeImmutable($data['createdAt']),
            content: $data['content'] ?? null,
            context: $data['context'] ?? null,
            refs: $data['refs'] ?? [],
        );
    }

    /**
     * Build the user's reading lists view (array of RedisReadingListView), collating articles.
     * Handles all DB lookups and view construction.
     *
     * @param EntityManagerInterface $em
     * @param string $pubkey
     * @return RedisReadingListView[]
     */
    public function buildUserReadingListsView(EntityManagerInterface $em, string $pubkey): array
    {
        $readingListsRaw = $em->getRepository(Event::class)
            ->findBy(['pubkey' => $pubkey, 'kind' => KindsEnum::PUBLICATION_INDEX->value], ['created_at' => 'DESC']) ?? [];
        $readingListsRaw = array_reduce($readingListsRaw, function ($carry, $item) {
            $slug = $item->getSlug();
            if (!isset($carry[$slug])) {
                $carry[$slug] = $item;
            }
            return $carry;
        }, []);
        $readingListsRaw = array_values($readingListsRaw);
        $readingLists = [];
        foreach ($readingListsRaw as $list) {
            $tags = $list->getTags();
            $articleSlugs = [];
            foreach ($tags as $tag) {
                if (is_array($tag) && $tag[0] === 'a' && isset($tag[1])) {
                    // Slug from coordinate
                    $parts = explode(':', $tag[1], 3);
                    $articleSlugs[] = $parts[2];
                }
            }
            $articles = [];
            if ($articleSlugs) {
                $dbArticles = $em->getRepository(Article::class)
                    ->createQueryBuilder('a')
                    ->where('a.slug IN (:slugs)')
                    ->setParameter('slugs', $articleSlugs)
                    ->getQuery()->getResult();
                $dbArticlesBySlug = [];
                foreach ($dbArticles as $a) {
                    $dbArticlesBySlug[$a->getSlug()] = $a;
                }
                foreach ($articleSlugs as $slug) {
                    $a = $dbArticlesBySlug[$slug] ?? null;
                    if ($a) {
                        $articles[] = $this->articleBaseObject($a);
                    }
                }
            }
            $readingLists[] = new RedisReadingListView(
                $list->getTitle(),
                $list->getSummary(),
                $articles
            );
        }
        return $readingLists;
    }
}
