<?php

namespace App\Service\RSS;

use App\Enum\KindsEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Service for converting RSS feed items to Nostr longform events
 */
class RssToNostrConverter
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Convert an RSS item to a Nostr longform event (kind 30023)
     *
     * @param array<string, mixed> $rssItem The RSS item data
     * @return array{kind:int,content:string,tags:list<list<string>>} Unsigned event payload
     */
    public function convertToNostrEvent(
        array $rssItem
    ): array {
        $tags = [];

        // Set content (without appending the link)
        $content = $rssItem['content'] ?? $rssItem['description'] ?? '';
        // Generate unique slug from title and timestamp
        $slug = $this->generateSlug($rssItem['title'], $rssItem['pubDate']);
        $tags[] = ['d', $slug];

        // Add title tag
        if (!empty($rssItem['title'])) {
            $tags[] = ['title', $rssItem['title']];
        }

        // Add summary tag
        if (!empty($rssItem['description'])) {
            $summary = $this->htmlToPlainText($rssItem['description']);
            $tags[] = ['summary', $summary];
        }

        // Add image tag if available
        if (!empty($rssItem['image'])) {
            $tags[] = ['image', $rssItem['image']];
        }

        // Add published_at tag
        if ($rssItem['pubDate'] instanceof \DateTimeImmutable) {
            $tags[] = ['published_at', (string) $rssItem['pubDate']->getTimestamp()];
        }

        // Add source tag for original article URL
        if (!empty($rssItem['link'])) {
            $tags[] = ['source', $rssItem['link']];
        }

        // Add reference to original URL (r tag for generic reference)
        if (!empty($rssItem['link'])) {
            $tags[] = ['r', $rssItem['link']];
        }

        // Add client tag to indicate source
        $tags[] = ['client', 'newsroom-rss-aggregator'];

        $this->logger->info('Created Nostr event from RSS item', [
            'title' => $rssItem['title'],
            'slug' => $slug,
        ]);

        return [
            'kind' => KindsEnum::LONGFORM->value,
            'content' => $content,
            'tags' => $tags,
        ];
    }

    /**
     * Generate a unique slug from title and timestamp
     */
    private function generateSlug(string $title, ?\DateTimeImmutable $pubDate): string
    {
        $slugger = new AsciiSlugger();
        $baseSlug = $slugger->slug($title)->lower()->toString();

        // Limit base slug length
        if (strlen($baseSlug) > 50) {
            $baseSlug = substr($baseSlug, 0, 50);
        }

        // Add timestamp for uniqueness
        $timestamp = $pubDate ? $pubDate->format('Y-m-d-His') : date('Y-m-d-His');

        return $baseSlug . '-' . $timestamp;
    }

    /**
     * Convert HTML content to plain text
     * Strips HTML tags and decodes HTML entities
     */
    private function htmlToPlainText(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // Strip HTML tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Trim
        return trim($text);
    }

    /**
     * Check if a slug already exists in the database
     * This is used by the command to detect duplicates
     *
     * @param array<string, mixed> $rssItem
     */
    public function generateSlugForItem(array $rssItem): string
    {
        return $this->generateSlug($rssItem['title'], $rssItem['pubDate']);
    }
}
