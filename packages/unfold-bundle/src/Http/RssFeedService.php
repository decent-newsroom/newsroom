<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Http;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates a publication-local RSS 2.0 response.
 */
final class RssFeedService
{
    private const CACHE_CONTROL = 'public, max-age=600';

    public function __construct(
        private readonly PublicationUrlGenerator $urls,
    ) {
    }

    /**
     * @param PostData[] $posts
     */
    public function createResponse(
        SiteConfig $site,
        array $posts,
        ?string $title = null,
        ?string $description = null,
    ): Response {
        $channelTitle = $title ?? $site->title;
        $channelDescription = $description ?? $site->description;
        $link = $this->urls->home();
        $rssLink = $this->urls->rss();

        $xml = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/" xmlns:atom="http://www.w3.org/2005/Atom">',
            '<channel>',
            $this->element('title', $channelTitle),
            $this->element('description', $channelDescription),
            $this->element('link', $link),
            $this->element('language', 'en'),
            '<atom:link href="' . $this->escape($rssLink) . '" rel="self" type="application/rss+xml"/>',
        ];

        if ($site->logo !== null && $site->logo !== '') {
            $xml[] = '<image>';
            $xml[] = $this->element('url', $this->urls->absolute($site->logo));
            $xml[] = $this->element('title', $channelTitle);
            $xml[] = $this->element('link', $link);
            $xml[] = '</image>';
        }

        foreach ($posts as $post) {
            $xml[] = '<item>';
            $xml[] = $this->element('title', $post->title);
            $xml[] = $this->element('description', $post->summary);
            $xml[] = $this->element('link', $this->urls->post($post));
            $xml[] = '<guid isPermaLink="false">' . $this->escape($post->coordinate) . '</guid>';
            $xml[] = $this->element('author', $post->pubkey);
            $xml[] = $this->element('pubDate', gmdate('D, d M Y H:i:s \G\M\T', $post->publishedAt));

            if ($post->image !== null && $post->image !== '') {
                $xml[] = '<media:content url="' . $this->escape($this->urls->absolute($post->image)) . '" medium="image"/>';
            }

            $xml[] = '</item>';
        }

        $xml[] = '</channel>';
        $xml[] = '</rss>';

        return new Response(
            implode("\n", $xml) . "\n",
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/rss+xml; charset=UTF-8',
                'Cache-Control' => self::CACHE_CONTROL,
            ],
        );
    }

    /**
     * Alias kept convenient for controllers that name response factories `build`.
     *
     * @param PostData[] $posts
     */
    public function build(
        SiteConfig $site,
        array $posts,
        ?string $title = null,
        ?string $description = null,
    ): Response {
        return $this->createResponse($site, $posts, $title, $description);
    }

    private function element(string $name, string $value): string
    {
        return '<' . $name . '>' . $this->escape($value) . '</' . $name . '>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
