<?php

namespace App\UnfoldBundle\Theme;

use App\UnfoldBundle\Config\SiteConfig;
use App\UnfoldBundle\Content\CategoryData;
use App\UnfoldBundle\Content\PostData;

/**
 * Builds Ghost-compatible context for Handlebars templates
 */
class ContextBuilder
{
    /**
     * Build context for home page
     *
     * @param CategoryData[] $categories
     * @param PostData[] $posts
     */
    public function buildHomeContext(SiteConfig $site, array $categories, array $posts): array
    {
        return [
            '@site' => $this->buildSiteContext($site, $categories),
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
        return [
            '@site' => $this->buildSiteContext($site, $categories),
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
        return [
            '@site' => $this->buildSiteContext($site, $categories),
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
        ];
    }

    /**
     * Build post context for list views
     */
    private function buildPostListItemContext(PostData $post): array
    {
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
        ];
    }

    /**
     * Build full post context for detail page
     */
    private function buildSinglePostContext(PostData $post): array
    {
        return [
            'id' => $post->coordinate,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->summary,
            'html' => $this->markdownToHtml($post->content),
            'url' => '/a/' . $post->slug,
            'feature_image' => $post->image,
            'published_at' => date('c', $post->publishedAt),
            'published_at_formatted' => $post->getPublishedDate(),
            'reading_time' => $this->estimateReadingTime($post->content),
            'primary_author' => [
                'id' => $post->pubkey,
                'name' => 'Author', // TODO: fetch author metadata
                'slug' => substr($post->pubkey, 0, 8),
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
     * Convert markdown to HTML (basic implementation)
     * TODO: Use proper markdown parser
     */
    private function markdownToHtml(string $markdown): string
    {
        // For now, just wrap in paragraph tags and handle basic formatting
        // This should be replaced with a proper markdown parser like league/commonmark
        $html = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');
        $html = nl2br($html);

        return $html;
    }
}

