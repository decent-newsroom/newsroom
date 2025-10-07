<?php

namespace App\Service;

use App\Entity\Nzine;
use App\Enum\KindsEnum;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Service for converting RSS feed items to Nostr longform events
 */
class RssToNostrConverter
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EncryptionService $encryptionService
    ) {
    }

    /**
     * Convert an RSS item to a Nostr longform event (kind 30023)
     *
     * @param array $rssItem The RSS item data
     * @param array|null $matchedCategory The matched nzine category (null if no match)
     * @param Nzine $nzine The nzine entity
     * @param string|null $categoryIndexEventId The event ID of the category index (for 'a' tag)
     * @return Event The created and signed Nostr event
     */
    public function convertToNostrEvent(
        array $rssItem,
        ?array $matchedCategory,
        Nzine $nzine,
        ?string $categoryIndexEventId = null
    ): Event {
        $bot = $nzine->getNzineBot();
        if (!$bot) {
            throw new \RuntimeException('Nzine bot not found');
        }

        $bot->setEncryptionService($this->encryptionService);
        $privateKey = $bot->getNsec();

        if (!$privateKey) {
            throw new \RuntimeException('Bot private key not found');
        }

        // Create the event
        $event = new Event();
        $event->setKind(KindsEnum::LONGFORM->value);

        // Set content (without appending the link)
        $content = $rssItem['content'] ?? $rssItem['description'] ?? '';
        $event->setContent($content);

        // Generate unique slug from title and timestamp
        $slug = $this->generateSlug($rssItem['title'], $rssItem['pubDate']);
        $event->addTag(['d', $slug]);

        // Add title tag
        if (!empty($rssItem['title'])) {
            $event->addTag(['title', $rssItem['title']]);
        }

        // Add summary tag
        if (!empty($rssItem['description'])) {
            $summary = $this->htmlToPlainText($rssItem['description']);
            $event->addTag(['summary', $summary]);
        }

        // Add image tag if available
        if (!empty($rssItem['image'])) {
            $event->addTag(['image', $rssItem['image']]);
        }

        // Add published_at tag
        if ($rssItem['pubDate'] instanceof \DateTimeImmutable) {
            $event->addTag(['published_at', (string) $rssItem['pubDate']->getTimestamp()]);
        }

        // Add category tag (t tag) - only if category matched
        if ($matchedCategory && isset($matchedCategory['slug'])) {
            $event->addTag(['t', $matchedCategory['slug']]);
        }

        // Add source tag for original article URL
        if (!empty($rssItem['link'])) {
            $event->addTag(['source', $rssItem['link']]);
        }

        // Add reference to original URL (r tag for generic reference)
        if (!empty($rssItem['link'])) {
            $event->addTag(['r', $rssItem['link']]);
        }

        // Add reference to category index if provided and category matched
        if ($categoryIndexEventId && $matchedCategory && isset($matchedCategory['slug'])) {
            $npub = $nzine->getNpub();
            $event->addTag(['a', KindsEnum::PUBLICATION_INDEX->value . ':' . $npub . ':' . $matchedCategory['slug']]);
        }

        // Add client tag to indicate source
        $event->addTag(['client', 'newsroom-rss-aggregator']);

        // Sign the event
        $signer = new Sign();
        $signer->signEvent($event, $privateKey);

        $this->logger->info('Created Nostr event from RSS item', [
            'title' => $rssItem['title'],
            'slug' => $slug,
            'category' => $matchedCategory['name'] ?? null,
        ]);

        return $event;
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
     */
    public function generateSlugForItem(array $rssItem): string
    {
        return $this->generateSlug($rssItem['title'], $rssItem['pubDate']);
    }
}
