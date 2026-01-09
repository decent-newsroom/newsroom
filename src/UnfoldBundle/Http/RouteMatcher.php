<?php

namespace App\UnfoldBundle\Http;

use App\UnfoldBundle\Config\SiteConfig;
use App\UnfoldBundle\Content\CategoryData;

/**
 * Matches URL paths to page types for Unfold sites
 */
class RouteMatcher
{
    public const PAGE_HOME = 'home';
    public const PAGE_CATEGORY = 'category';
    public const PAGE_POST = 'post';
    public const PAGE_NOT_FOUND = 'not_found';

    /**
     * Match a URL path to a page type and extract parameters
     *
     * @return array{type: string, slug?: string, category?: CategoryData}
     */
    public function match(string $path, SiteConfig $site, array $categories): array
    {
        $path = '/' . ltrim($path, '/');

        // Home page
        if ($path === '/' || $path === '') {
            return ['type' => self::PAGE_HOME];
        }

        // Post page: /a/{slug}
        if (preg_match('#^/a/([^/]+)$#', $path, $matches)) {
            return [
                'type' => self::PAGE_POST,
                'slug' => $matches[1],
            ];
        }

        // Category page: /{slug}
        if (preg_match('#^/([^/]+)/?$#', $path, $matches)) {
            $slug = $matches[1];

            // Find category by slug
            foreach ($categories as $category) {
                if ($category->slug === $slug) {
                    return [
                        'type' => self::PAGE_CATEGORY,
                        'slug' => $slug,
                        'category' => $category,
                    ];
                }
            }

            // Slug doesn't match any category
            return ['type' => self::PAGE_NOT_FOUND];
        }

        return ['type' => self::PAGE_NOT_FOUND];
    }
}

