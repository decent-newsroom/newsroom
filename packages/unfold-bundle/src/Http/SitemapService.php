<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Http;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates a publication-local XML sitemap response.
 */
final class SitemapService
{
    private const CACHE_CONTROL = 'public, max-age=600';

    public function __construct(
        private readonly PublicationUrlGenerator $urls,
    ) {
    }

    /**
     * @param CategoryData[] $categories
     * @param PostData[] $posts
     */
    public function createResponse(SiteConfig $site, array $categories, array $posts): Response
    {
        $locations = [$this->urls->home()];

        foreach ($categories as $category) {
            $locations[] = $this->urls->category($category);
        }

        foreach ($posts as $post) {
            $locations[] = $this->urls->post($post);
        }

        $locations = array_values(array_unique($locations));
        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($locations as $location) {
            $xml[] = '<url><loc>' . $this->escape($location) . '</loc></url>';
        }

        $xml[] = '</urlset>';

        return new Response(
            implode("\n", $xml) . "\n",
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Cache-Control' => self::CACHE_CONTROL,
            ],
        );
    }

    /**
     * @param CategoryData[] $categories
     * @param PostData[] $posts
     */
    public function build(SiteConfig $site, array $categories, array $posts): Response
    {
        return $this->createResponse($site, $categories, $posts);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
