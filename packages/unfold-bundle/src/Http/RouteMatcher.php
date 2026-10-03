<?php

namespace DecentNewsroom\UnfoldBundle\Http;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\ContentKindPolicy;
use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NPub;

/**
 * Matches URL paths to page types for Unfold sites
 */
class RouteMatcher
{
    public const PAGE_HOME = 'home';
    public const PAGE_ABOUT = 'about';
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

        // Administration is reserved even if an index contains a category named admin.
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return ['type' => self::PAGE_NOT_FOUND];
        }

        // Content identifiers may contain encoded slashes or look like files.
        if (preg_match('#^/(npub1[0-9a-z]+)/(a|chapter|wiki|spec)/(.+)$#sD', $path, $matches)) {
            try {
                $author = (new Bech32($matches[1]))->data;
                if (!$author instanceof NPub) {
                    return ['type' => self::PAGE_NOT_FOUND];
                }
                $reference = ContentReference::fromInput(
                    ContentKindPolicy::kindForSegment($matches[2]) . ':' . $author->data . ':' . rawurldecode($matches[3]),
                );

                return ['type' => self::PAGE_POST, 'slug' => $reference->identifier, 'coordinate' => $reference->coordinate];
            } catch (\Exception | \TypeError) {
                return ['type' => self::PAGE_NOT_FOUND];
            }
        }
        if (preg_match('#^/a/(.+)$#sD', $path, $matches)) {
            return ['type' => self::PAGE_POST, 'slug' => rawurldecode($matches[1])];
        }

        // Quick reject for static file requests (favicon.ico, robots.txt, etc.)
        if ($this->isStaticFileRequest($path)) {
            return ['type' => self::PAGE_NOT_FOUND];
        }

        // Home page
        if ($path === '/' || $path === '') {
            return ['type' => self::PAGE_HOME];
        }

        // About is reserved ahead of category slugs.
        if ($path === '/about' || $path === '/about/') {
            return ['type' => self::PAGE_ABOUT];
        }

        // Category page: /{slug}
        if (preg_match('#^/([^/]+)/?$#', $path, $matches)) {
            $slug = rawurldecode($matches[1]);

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
