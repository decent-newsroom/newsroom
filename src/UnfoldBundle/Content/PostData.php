<?php

namespace App\UnfoldBundle\Content;

/**
 * Post/article data derived from article event (kind 30023)
 */
readonly class PostData
{
    /**
     * @param string $slug Article slug (d tag)
     * @param string $title Article title
     * @param string $summary Article summary/excerpt
     * @param string $content Article content (markdown)
     * @param string|null $image Featured image URL
     * @param int $publishedAt Unix timestamp
     * @param string $pubkey Author's hex pubkey
     * @param string $coordinate Article coordinate (kind:pubkey:slug)
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $summary,
        public string $content,
        public ?string $image,
        public int $publishedAt,
        public string $pubkey,
        public string $coordinate,
    ) {}

    /**
     * Create PostData from a raw Nostr event object
     */
    public static function fromEvent(object $event): self
    {
        $tags = $event->tags ?? [];
        $slug = '';
        $title = '';
        $summary = '';
        $image = null;
        $publishedAt = $event->created_at ?? time();

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            match ($tag[0]) {
                'd' => $slug = $tag[1],
                'title' => $title = $tag[1],
                'summary' => $summary = $tag[1],
                'image', 'thumb' => $image = $tag[1],
                'published_at' => $publishedAt = (int) $tag[1],
                default => null,
            };
        }

        $kind = $event->kind ?? 30023;
        $pubkey = $event->pubkey ?? '';
        $coordinate = "{$kind}:{$pubkey}:{$slug}";

        return new self(
            slug: $slug,
            title: $title,
            summary: $summary,
            content: $event->content ?? '',
            image: $image,
            publishedAt: $publishedAt,
            pubkey: $pubkey,
            coordinate: $coordinate,
        );
    }

    /**
     * Get formatted publish date
     */
    public function getPublishedDate(string $format = 'F j, Y'): string
    {
        return date($format, $this->publishedAt);
    }
}

