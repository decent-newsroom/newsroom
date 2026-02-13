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
     * Common static file extensions that should return 404 quickly
     */
    private const STATIC_FILE_EXTENSIONS = [
        'ico', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp',
        'css', 'js', 'map', 'woff', 'woff2', 'ttf', 'eot',
        'txt', 'xml', 'json', 'webmanifest',
    ];

    /**
     * Match a URL path to a page type and extract parameters
     *
     * @return array{type: string, slug?: string, category?: CategoryData}
     */
    public function match(string $path, SiteConfig $site, array $categories): array
    {
        $path = '/' . ltrim($path, '/');

        // Quick reject for static file requests (favicon.ico, robots.txt, etc.)
        if ($this->isStaticFileRequest($path)) {
            return ['type' => self::PAGE_NOT_FOUND];
        }

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

    /**
     * Check if the path looks like a static file request
     */
    private function isStaticFileRequest(string $path): bool
    {
        // Check for file extension
        if (preg_match('/\.([a-z0-9]+)$/i', $path, $matches)) {
            $extension = strtolower($matches[1]);
            return in_array($extension, self::STATIC_FILE_EXTENSIONS, true);
        }

        return false;
    }
}

