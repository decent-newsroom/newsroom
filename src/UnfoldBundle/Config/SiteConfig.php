<?php

namespace App\UnfoldBundle\Config;

/**
 * Site configuration derived from AppData + root magazine event (kind 30040)
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
     * @param string $theme Theme name from AppData
     */
    public function __construct(
        public string $naddr,
        public string $title,
        public string $description,
        public ?string $logo,
        public array $categories,
        public string $pubkey,
        public string $theme = 'default',
    ) {}

    /**
     * Create SiteConfig from a raw Nostr event object and AppData
     */
    public static function fromEvent(object $event, string $naddr, string $theme = 'default'): self
    {
        $tags = $event->tags ?? [];
        $title = '';
        $description = '';
        $logo = null;
        $categories = [];

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            match ($tag[0]) {
                'title', 'name' => $title = $tag[1],
                'description', 'summary' => $description = $tag[1],
                'image', 'thumb', 'logo' => $logo = $tag[1],
                'a' => $categories[] = $tag[1], // category coordinate (30040:pubkey:slug)
                default => null,
            };
        }

        // Fallback: try content as JSON for title/description
        if (empty($title) && !empty($event->content)) {
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
            pubkey: $event->pubkey ?? '',
            theme: $theme,
        );
    }
}

