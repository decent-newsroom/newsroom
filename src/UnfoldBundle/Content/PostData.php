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
     * @param string|null $lud16 Lightning address (LUD-16)
     * @param string|null $lud06 LNURL (LUD-06)
     * @param array $zapSplits Zap split configuration
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
        public ?string $lud16 = null,
        public ?string $lud06 = null,
        public array $zapSplits = [],
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
        $lud16 = null;
        $lud06 = null;
        $zapSplits = [];

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
                'lud16' => $lud16 = $tag[1],
                'lud06' => $lud06 = $tag[1],
                'zap' => $zapSplits[] = [
                    'recipient' => $tag[1] ?? '',
                    'relay' => $tag[2] ?? null,
                    'weight' => isset($tag[3]) ? (int) $tag[3] : 1,
                ],
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
            lud16: $lud16,
            lud06: $lud06,
            zapSplits: $zapSplits,
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

