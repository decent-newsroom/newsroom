<?php

namespace App\UnfoldBundle\Theme;

use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\UnfoldBundle\Config\SiteConfig;
use App\UnfoldBundle\Content\CategoryData;
use App\UnfoldBundle\Content\PostData;
use App\Util\CommonMark\MarkdownConverterInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Builds Ghost-compatible context for Handlebars templates
 */
class ContextBuilder
{
    private const CACHE_TTL = 86400; // 24 hours - content is fixed per event

    public function __construct(
        private readonly MarkdownConverterInterface $converter,
        private readonly CacheItemPoolInterface $cache,
        private readonly RedisCacheService $redisCacheService,
        private readonly EventRepository $eventRepository,
    ) {}

    /**
     * Build context for home page
     *
     * @param CategoryData[] $categories
     * @param PostData[] $posts
     */
    public function buildHomeContext(SiteConfig $site, array $categories, array $posts): array
    {
        $siteContext = $this->buildSiteContext($site, $categories);
        return [
            '@site' => $siteContext,
            'site' => $siteContext,  // Also provide without @ for LightnCandy compatibility
            '@custom' => $this->buildCustomContext(),
            '@pageType' => 'home',
            'posts' => array_map([$this, 'buildPostListItemContext'], $posts),
            'pagination' => $this->buildPaginationContext(count($posts)),
        ];
    }

    /**
     * Build context for category page
     *
     * @param CategoryData[] $categories
     * @param PostData[] $posts
     */
    public function buildCategoryContext(
        SiteConfig $site,
        array $categories,
        CategoryData $category,
        array $posts
    ): array {
        $siteContext = $this->buildSiteContext($site, $categories);
        return [
            '@site' => $siteContext,
            'site' => $siteContext,  // Also provide without @ for LightnCandy compatibility
            '@custom' => $this->buildCustomContext(),
            '@pageType' => 'tag',
            'category' => [
                'slug' => $category->slug,
                'title' => $category->title,
                'summary' => $category->summary,
                'url' => '/' . $category->slug,
            ],
            'posts' => array_map([$this, 'buildPostListItemContext'], $posts),
            'pagination' => $this->buildPaginationContext(count($posts)),
        ];
    }

    /**
     * Build context for post page
     *
     * @param CategoryData[] $categories
     */
    public function buildPostContext(SiteConfig $site, array $categories, PostData $post): array
    {
        $siteContext = $this->buildSiteContext($site, $categories);
        return [
            '@site' => $siteContext,
            'site' => $siteContext,  // Also provide without @ for LightnCandy compatibility
            '@custom' => $this->buildCustomContext(),
            '@pageType' => 'post',
            'post' => $this->buildSinglePostContext($post),
        ];
    }

    /**
     * Build @site context (Ghost-compatible)
     *
     * @param CategoryData[] $categories
     */
    private function buildSiteContext(SiteConfig $site, array $categories): array
    {
        $navigation = array_map(fn(CategoryData $cat) => [
            'label' => $cat->title,
            'url' => '/' . $cat->slug,
            'slug' => $cat->slug,
        ], $categories);

        // Get magazine creator's lightning address from metadata
        $creatorMetadata = $this->redisCacheService->getMetadata($site->pubkey);
        $creatorLud16 = $creatorMetadata->lud16;
        $creatorLud06 = $creatorMetadata->lud06;

        // Handle lud16/lud06 as arrays (take first element)
        if (is_array($creatorLud16)) {
            $creatorLud16 = !empty($creatorLud16) ? $creatorLud16[0] : null;
        }
        if (is_array($creatorLud06)) {
            $creatorLud06 = !empty($creatorLud06) ? $creatorLud06[0] : null;
        }

        return [
            'title' => $site->title,
            'description' => $site->description,
            'logo' => $site->logo,
            'url' => '/',
            'navigation' => $navigation,
            'locale' => 'en',
            'members_enabled' => false,
            'creator_pubkey' => $site->pubkey,
            'creator_lud16' => $creatorLud16,
            'creator_lud06' => $creatorLud06,
        ];
    }

    /**
     * Build @custom context (theme settings) with defaults
     */
    private function buildCustomContext(): array
    {
        return [
            'navigation_layout' => 'Logo on the left',
            'header_style' => 'Center aligned',
            'feed_layout' => 'Classic',
            'color_scheme' => 'Light',
            'post_image_style' => 'Wide',
            'title_font' => 'Modern sans-serif',
            'body_font' => 'Modern sans-serif',
            'show_publication_cover' => false,
            'email_signup_text' => 'Sign up for more like this.',
        ];
    }

    /**
     * Build post context for list views
     */
    private function buildPostListItemContext(PostData $post): array
    {
        // Fetch author metadata from Redis cache
        $authorMetadata = $this->redisCacheService->getMetadata($post->pubkey);
        $authorName = $authorMetadata->displayName ?: $authorMetadata->name ?: 'Author';

        return [
            'id' => $post->coordinate,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->summary,
            'url' => '/a/' . $post->slug,
            'feature_image' => $post->image,
            'published_at' => date('c', $post->publishedAt),
            'published_at_formatted' => $post->getPublishedDate(),
            'reading_time' => $this->estimateReadingTime($post->content),
            'primary_author' => [
                'id' => $post->pubkey,
                'name' => $authorName,
                'slug' => substr($post->pubkey, 0, 8),
                'profile_image' => $authorMetadata->picture,
            ],
        ];
    }

    /**
     * Build full post context for detail page
     */
    private function buildSinglePostContext(PostData $post): array
    {
        // Fetch author metadata from Redis cache
        $authorMetadata = $this->redisCacheService->getMetadata($post->pubkey);
        $authorName = $authorMetadata->displayName ?: $authorMetadata->name ?: 'Author';

        // Use lightning address from post data first (if specified in article tags),
        // otherwise fall back to author's metadata
        $lud16 = $post->lud16 ?: $authorMetadata->lud16;
        $lud06 = $post->lud06 ?: $authorMetadata->lud06;

        // Handle lud16/lud06 as arrays (take first element)
        if (is_array($lud16)) {
            $lud16 = !empty($lud16) ? $lud16[0] : null;
        }
        if (is_array($lud06)) {
            $lud06 = !empty($lud06) ? $lud06[0] : null;
        }

        // Fetch comments and related zaps
        $comments = $this->buildCommentsContext($post->coordinate);
        $commentsCount = count(array_filter(
            $comments,
            static fn (array $item): bool => !($item['is_zap'] ?? false)
        ));

        return [
            'id' => $post->coordinate,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->summary,
            'html' => $this->markdownToHtml($post->content, $post->coordinate),
            'url' => '/a/' . $post->slug,
            'feature_image' => $post->image,
            'published_at' => date('c', $post->publishedAt),
            'published_at_formatted' => $post->getPublishedDate(),
            'reading_time' => $this->estimateReadingTime($post->content),
            'primary_author' => [
                'id' => $post->pubkey,
                'name' => $authorName,
                'slug' => substr($post->pubkey, 0, 8),
                'profile_image' => $authorMetadata->picture,
            ],
            'zap' => [
                'pubkey' => $post->pubkey,
                'lud16' => $lud16,
                'lud06' => $lud06,
                'splits' => $post->zapSplits,
            ],
            'comments' => $comments,
            'comments_count' => $commentsCount,
            'has_thread_activity' => [] !== $comments,
        ];
    }

    /**
     * Build pagination context
     */
    private function buildPaginationContext(int $totalPosts, int $page = 1, int $perPage = 10): array
    {
        $totalPages = max(1, ceil($totalPosts / $perPage));

        return [
            'page' => $page,
            'pages' => $totalPages,
            'total' => $totalPosts,
            'limit' => $perPage,
            'prev' => $page > 1 ? $page - 1 : null,
            'next' => $page < $totalPages ? $page + 1 : null,
        ];
    }

    /**
     * Estimate reading time in minutes
     */
    private function estimateReadingTime(string $content): int
    {
        $wordCount = str_word_count(strip_tags($content));
        $readingTime = ceil($wordCount / 200); // Assume 200 words per minute

        return max(1, (int) $readingTime);
    }

    /**
     * Convert markdown to HTML using the CommonMark converter with Nostr link support.
     * Results are cached by event coordinate since content is immutable.
     */
    private function markdownToHtml(string $markdown, string $coordinate): string
    {
        // Cache key based on event coordinate (content is fixed per event)
        $cacheKey = 'unfold_html_' . str_replace([':', '/'], '_', $coordinate);

        try {
            $item = $this->cache->getItem($cacheKey);

            if ($item->isHit()) {
                return $item->get();
            }

            // Convert markdown to HTML
            $html = $this->converter->convertToHTML($markdown);

            // Cache the result
            $item->set($html);
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);

            return $html;
        } catch (\Throwable $e) {
            // Fallback to basic HTML escaping if conversion or caching fails
            $html = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');
            return nl2br($html);
        }
    }

    /**
     * Fetch and build comments context for a post coordinate
     *
     * @param string $coordinate Article coordinate (kind:pubkey:identifier)
     * @return array Array of comment objects
     */
    private function buildCommentsContext(string $coordinate): array
    {
        try {
            $events = $this->eventRepository->findCommentsByCoordinate($coordinate);

            if (empty($events)) {
                return [];
            }

            // Extract unique pubkeys for metadata fetch
            $pubkeys = array_unique(array_filter(array_map(
                fn($event) => $event->getPubkey(),
                $events
            )));

            // Fetch all author metadata at once
            $metadataMap = [];
            if (!empty($pubkeys)) {
                $metadataArray = $this->redisCacheService->getMultipleMetadata($pubkeys);
                foreach ($metadataArray as $pubkey => $metadata) {
                    $metadataMap[$pubkey] = $metadata;
                }
            }

            // Build comments array
            $comments = [];
            foreach ($events as $event) {
                $pubkey = $event->getPubkey();
                $metadata = $metadataMap[$pubkey] ?? null;

                $commentData = [
                    'id' => $event->getId(),
                    'kind' => $event->getKind(),
                    'pubkey' => $pubkey,
                    'content' => $event->getContent(),
                    'created_at' => $event->getCreatedAt(),
                    'created_at_formatted' => date('F j, Y', $event->getCreatedAt()),
                    'author' => [
                        'name' => $metadata?->displayName ?: $metadata?->name ?: substr($pubkey, 0, 8) . '…',
                        'pic' => $metadata?->picture,
                        'pubkey' => $pubkey,
                    ],
                ];

                // Handle zaps (kind 9735)
                if ((int) $event->getKind() === 9735) {
                    $commentData['is_zap'] = true;
                    $commentData['zap_amount'] = $this->extractZapAmount($event);
                    $commentData['zap_pubkey'] = $this->extractZapPubkey($event);
                } else {
                    $commentData['is_zap'] = false;
                }

                $comments[] = $commentData;
            }

            // Sort by created_at descending
            usort($comments, fn($a, $b) => $b['created_at'] - $a['created_at']);

            return $comments;
        } catch (\Throwable $e) {
            // DB unavailable or other error – return empty list
            return [];
        }
    }

    /**
     * Extract zap amount from a kind 9735 event (in sats)
     */
    private function extractZapAmount(object $event): ?int
    {
        $tags = $event->getTags() ?? [];
        if (!is_array($tags)) {
            return null;
        }

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            if ($tag[0] === 'description') {
                try {
                    $description = json_decode($tag[1], true);
                    if (is_array($description) && isset($description['tags'])) {
                        // Look for amount tag in the description
                        foreach ($description['tags'] as $dtag) {
                            if (is_array($dtag) && count($dtag) >= 2 && $dtag[0] === 'amount') {
                                $msats = (int) $dtag[1];
                                return intdiv($msats, 1000); // Convert millisats to sats
                            }
                        }
                    }

                    // Fallback: check for bolt11 in description
                    if (is_array($description) && isset($description['bolt11'])) {
                        return $this->parseBolt11ToSats($description['bolt11']);
                    }
                } catch (\Throwable) {
                    // Ignore JSON decode errors
                }
            }

            // Check for bolt11 tag at top level
            if ($tag[0] === 'bolt11') {
                return $this->parseBolt11ToSats($tag[1]);
            }
        }

        return null;
    }

    /**
     * Extract zapper pubkey from a kind 9735 event
     */
    private function extractZapPubkey(object $event): ?string
    {
        $tags = $event->getTags() ?? [];
        if (!is_array($tags)) {
            return null;
        }

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            if ($tag[0] === 'description') {
                try {
                    $description = json_decode($tag[1], true);
                    if (is_array($description) && isset($description['pubkey'])) {
                        return $description['pubkey'];
                    }
                } catch (\Throwable) {
                    // Ignore JSON decode errors
                }
            }

            if ($tag[0] === 'P') {
                return $tag[1];
            }
        }

        return null;
    }

    /**
     * Simple BOLT11 invoice parser to extract sats amount
     */
    private function parseBolt11ToSats(string $bolt11): ?int
    {
        // Match pattern: ln + amount + rest
        // Amount format: [0-9]+[munp]? where m=milli, u=micro, n=nano, p=pico
        if (preg_match('/^lnbc?(\d+)([munp])?/i', strtolower($bolt11), $matches)) {
            $amount = (int) $matches[1];
            $unit = $matches[2] ?? '';

            return match ($unit) {
                'm' => intdiv($amount, 1000), // millis to sats
                'u' => 0, // micros → too small to be sats
                'n' => 0, // nanos → too small
                'p' => 0, // picos → too small
                default => $amount, // no unit = sats
            };
        }

        return null;
    }
}
