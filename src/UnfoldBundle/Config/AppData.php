<?php

namespace App\UnfoldBundle\Config;

/**
 * Unfold App Data from NIP-78 event (kind 30078)
 *
 * This is the configuration stored on Nostr that defines an Unfold site.
 * The UnfoldSite entity maps subdomains to the naddr of this app data event.
 *
 * Event structure:
 * - kind: 30078
 * - content: "Unfold App Config for 'Magazine Name'" (human-readable title)
 * - tags:
 *   - ["d", "<unique-site-identifier>"]
 *   - ["a", "<magazine-naddr>"]           // Required: root magazine event (kind 30040)
 *   - ["theme", "<theme-name>"]           // Optional: defaults to "default"
 *   - ["alt", "Unfold App Config"]        // Alt text for clients that don't understand this event
 */
readonly class AppData
{
    public function __construct(
        public string $naddr,           // naddr of this app data event
        public string $magazineNaddr,   // naddr of root magazine event (kind 30040)
        public string $theme = 'default',
        public string $title = '',      // human-readable title from content
    ) {}

    /**
     * Create AppData from a raw NIP-78 event object
     */
    public static function fromEvent(object $event, string $naddr): self
    {
        $tags = $event->tags ?? [];
        $magazineNaddr = '';
        $theme = 'default';

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            match ($tag[0]) {
                'a' => $magazineNaddr = $tag[1],
                'theme' => $theme = $tag[1],
                default => null,
            };
        }

        if (empty($magazineNaddr)) {
            throw new \InvalidArgumentException('AppData event missing required "a" tag with magazine naddr');
        }

        return new self(
            naddr: $naddr,
            magazineNaddr: $magazineNaddr,
            theme: $theme,
            title: $event->content ?? '',
        );
    }

    /**
     * Build tags array for publishing
     *
     * @param string $identifier The d-tag identifier for this app data event
     */
    public static function buildTags(string $identifier, string $magazineNaddr, string $theme = 'default'): array
    {
        return [
            ['d', $identifier],
            ['a', $magazineNaddr],
            ['theme', $theme],
            ['alt', 'Unfold App Config'],
        ];
    }

    /**
     * Build content string for publishing
     */
    public static function buildContent(string $magazineName): string
    {
        return "Unfold App Config for '{$magazineName}'";
    }
}

