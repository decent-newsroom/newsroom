<?php

namespace DecentNewsroom\UnfoldBundle\Content;

use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;

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
     * @param list<string> $authorPubkeys Valid category-index p tags in source order
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $coordinate,
        public string $summary = '',
        public array $articleCoordinates = [],
        public array $authorPubkeys = [],
    ) {}

    /**
     * Create CategoryData from a raw Nostr event object
     */
    public static function fromEvent(NostrEvent $event, string $coordinate): self
    {
        $tags = $event->tags;
        $slug = '';
        $title = '';
        $summary = '';
        $articleCoordinates = [];
        $authorPubkeys = [];

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            match ($tag[0]) {
                'd' => $slug = $tag[1],
                'title', 'name' => $title = $tag[1],
                'summary' => $summary = $tag[1],
                'a' => self::collectArticleReference($tag[1], $articleCoordinates),
                'p' => self::collectPubkey($tag[1], $authorPubkeys),
                default => null,
            };
        }

        // Fallback: try content as JSON for title/summary
        if ($event->content !== '' && (empty($title) || empty($summary))) {
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
            authorPubkeys: $authorPubkeys,
        );
    }

    /** @param list<string> $coordinates */
    private static function collectArticleReference(string $reference, array &$coordinates): void
    {
        if (str_starts_with($reference, '30023:')) {
            $coordinates[] = self::normalizeCoordinate($reference);
        }
    }

    /** @param list<string> $pubkeys */
    private static function collectPubkey(string $pubkey, array &$pubkeys): void
    {
        if (preg_match('/^[a-fA-F0-9]{64}$/D', $pubkey) === 1) {
            $pubkeys[] = strtolower($pubkey);
        }
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
