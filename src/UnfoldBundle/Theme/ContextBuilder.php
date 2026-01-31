<?php

namespace App\UnfoldBundle\Theme;

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

        return [
            'title' => $site->title,
            'description' => $site->description,
            'logo' => $site->logo,
            'url' => '/',
            'navigation' => $navigation,
            'locale' => 'en',
            'members_enabled' => false,
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
}

