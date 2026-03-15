<?php

namespace App\UnfoldBundle\Content;

/**
 * Category data derived from category event (kind 30040)
 */
readonly class CategoryData
{
    /**
     * @param string $slug Category slug (d tag)
     * @param string $title Category title
     * @param string $coordinate Category coordinate (kind:pubkey:slug)
     * @param string $summary Category summary
     * @param array<string> $articleCoordinates List of article coordinates (kind:pubkey:slug)
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $coordinate,
        public string $summary = '',
        public array $articleCoordinates = [],
    ) {}

    /**
     * Create CategoryData from a raw Nostr event object
     */
    public static function fromEvent(object $event, string $coordinate): self
    {
        $tags = $event->tags ?? [];
        $slug = '';
        $title = '';
        $summary = '';
        $articleCoordinates = [];

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            match ($tag[0]) {
                'd' => $slug = $tag[1],
                'title', 'name' => $title = $tag[1],
                'summary' => $summary = $tag[1],
                'a' => $articleCoordinates[] = self::normalizeCoordinate($tag[1]),
                default => null,
            };
        }

        // Fallback: try content as JSON for title/summary
        if ((!empty($event->content)) && (empty($title) || empty($summary))) {
            $content = json_decode($event->content, true);
            if (is_array($content)) {
                if (empty($title)) {
                    $title = $content['title'] ?? $content['name'] ?? '';
                }
                if (empty($summary)) {
                    $summary = $content['summary'] ?? $content['description'] ?? '';
                }
            }
        }

        return new self(
            slug: $slug,
            title: $title,
            coordinate: self::normalizeCoordinate($coordinate),
            summary: $summary,
            articleCoordinates: $articleCoordinates,
        );
    }

    /**
     * Normalize a coordinate string by lowercasing the pubkey portion.
     */
    private static function normalizeCoordinate(string $coordinate): string
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) >= 2) {
            $parts[1] = strtolower($parts[1]);
        }
        return implode(':', $parts);
    }
}
