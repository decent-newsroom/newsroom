<?php

namespace DecentNewsroom\UnfoldBundle\Config;

use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;

/**
 * Runtime configuration derived from the root magazine and local settings.
 */
readonly class SiteConfig
{
    /**
     * @param string $naddr The naddr of the root magazine event
     * @param string $title Site title from event
     * @param string $description Site description
     * @param string|null $logo Logo/image URL
     * @param array<string> $categories List of category event coordinates (kind:pubkey:d-tag)
     * @param string $pubkey Owner's hex pubkey
     * @param string $theme Locally selected theme
     * @param list<array{label: string, url: string}> $footerLinks Owner-selected footer links
     * @param list<string> $rootArticleCoordinates Direct root-index kind 30023 references
     * @param list<string> $authorPubkeys Valid root-index p tags in source order
     * @param list<string> $aboutRelayHints Relay hints from owner-selected naddr
     * @param list<string> $rootContentCoordinates All supported direct root leaf references
     * @param list<string> $rootReferenceCoordinates Root leaves and child indexes in signed order
     */
    public function __construct(
        public string $naddr,
        public string $title,
        public string $description,
        public ?string $logo,
        public array $categories,
        public string $pubkey,
        public string $theme = 'default',
        public array $footerLinks = [],
        public array $rootArticleCoordinates = [],
        public array $authorPubkeys = [],
        public ?string $aboutArticleCoordinate = null,
        public array $aboutRelayHints = [],
        public bool $isScoped = false,
        public array $rootContentCoordinates = [],
        public array $rootReferenceCoordinates = [],
    ) {}

    public function withTheme(string $theme): self
    {
        return new self($this->naddr, $this->title, $this->description, $this->logo, $this->categories, $this->pubkey, $theme, $this->footerLinks, $this->rootArticleCoordinates, $this->authorPubkeys, $this->aboutArticleCoordinate, $this->aboutRelayHints, $this->isScoped, $this->rootContentCoordinates, $this->rootReferenceCoordinates);
    }

    public function withSettings(PublicationSettings $settings): self
    {
        return new self($this->naddr, $this->title, $this->description, $this->logo, $this->categories, $this->pubkey, $settings->theme, $settings->footerLinks, $this->rootArticleCoordinates, $this->authorPubkeys, $settings->aboutArticleCoordinate, $settings->aboutRelayHints, $this->isScoped, $this->rootContentCoordinates, $this->rootReferenceCoordinates);
    }

    /**
     * Create SiteConfig from a root publication event and resolved theme
     */
    public static function fromEvent(NostrEvent $event, string $naddr, string $theme = 'default'): self
    {
        $tags = $event->tags;
        $title = '';
        $description = '';
        $logo = null;
        $categories = [];
        $rootArticleCoordinates = [];
        $authorPubkeys = [];
        $rootContentCoordinates = [];
        $rootReferenceCoordinates = [];

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            match ($tag[0]) {
                'title', 'name' => $title = $tag[1],
                'description', 'summary' => $description = $tag[1],
                'image', 'thumb', 'logo' => $logo = $tag[1],
                'a' => self::collectReference($tag[1], $categories, $rootArticleCoordinates, $rootContentCoordinates, $rootReferenceCoordinates),
                'p' => self::collectPubkey($tag[1], $authorPubkeys),
                default => null,
            };
        }

        // Fallback: try content as JSON for title/description
        if (empty($title) && $event->content !== '') {
            $content = json_decode($event->content, true);
            if (is_array($content)) {
                $title = $content['title'] ?? $content['name'] ?? '';
                $description = $description ?: ($content['description'] ?? '');
                $logo = $logo ?: ($content['image'] ?? $content['logo'] ?? null);
            }
        }

        return new self(
            naddr: $naddr,
            title: $title,
            description: $description,
            logo: $logo,
            categories: $categories,
            pubkey: $event->pubkey,
            theme: $theme,
            rootArticleCoordinates: $rootArticleCoordinates,
            authorPubkeys: $authorPubkeys,
            isScoped: ContentReference::isScoped($event),
            rootContentCoordinates: array_values(array_unique($rootContentCoordinates)),
            rootReferenceCoordinates: array_values(array_unique($rootReferenceCoordinates)),
        );
    }

    /**
     * @param list<string> $categories
     * @param list<string> $articles
     * @param list<string> $contents
     * @param list<string> $references
     */
    private static function collectReference(string $reference, array &$categories, array &$articles, array &$contents, array &$references): void
    {
        if (preg_match('/^30040:([^:]+):(.+)$/D', $reference) === 1) {
            $coordinate = self::normalizeCoordinate($reference);
            $categories[] = $coordinate;
            $references[] = $coordinate;
            return;
        }

        try {
            $content = ContentReference::fromInput($reference);
            $contents[] = $content->coordinate;
            $references[] = $content->coordinate;
            if ($content->kind === 30023) {
                $articles[] = $content->coordinate;
            }
        } catch (\InvalidArgumentException) {
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
     * Ensures consistency with graph storage (current_record.coord).
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
