<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Dto\NormalizedMedia;
use App\Util\ImetaParser;

/**
 * Converts provider responses and relay event objects into NormalizedMedia DTOs.
 *
 * Handles all three media kinds (20, 21, 22) and normalizes imeta metadata.
 *
 * @see §9 of multimedia-manager spec
 */
class MediaMetadataNormalizer
{
    /**
     * Normalize a raw Nostr event (stdClass or array) into NormalizedMedia objects.
     *
     * For kind 20: each imeta tag becomes a separate NormalizedMedia
     * For kind 21/22: all imeta tags are variants of one media post
     *
     * @return NormalizedMedia[]
     */
    public function normalizeEvent(object|array $event): array
    {
        $isArray = is_array($event);
        $kind = $isArray ? ($event['kind'] ?? 0) : ($event->kind ?? 0);
        $tags = $isArray ? ($event['tags'] ?? []) : ($event->tags ?? []);
        $content = $isArray ? ($event['content'] ?? '') : ($event->content ?? '');
        $pubkey = $isArray ? ($event['pubkey'] ?? '') : ($event->pubkey ?? '');
        $eventId = $isArray ? ($event['id'] ?? null) : ($event->id ?? null);
        $createdAt = $isArray ? ($event['created_at'] ?? null) : ($event->created_at ?? null);

        // Extract standard tags
        $title = $this->getTagValue($tags, 'title');
        $alt = $this->getTagValue($tags, 'alt');
        $publishedAt = $this->getTagValue($tags, 'published_at');
        $hashtags = $this->getTagValues($tags, 't');

        // Parse all imeta tags
        $imetaParsed = ImetaParser::parseAll($tags);

        if (empty($imetaParsed)) {
            // No imeta tags — create a minimal post record
            return [new NormalizedMedia(
                sourceType: 'post',
                ownerPubkey: $pubkey,
                eventId: $eventId,
                kind: (int) $kind,
                title: $title,
                description: $content,
                primaryUrl: '',
                createdAt: $createdAt ? (int) $createdAt : null,
                hashtags: $hashtags,
                rawTags: $tags,
            )];
        }

        if ($kind === 20) {
            // Kind 20 (Picture): each imeta = one image in the post
            // Return one NormalizedMedia per image but all share the same event context
            $results = [];
            foreach ($imetaParsed as $i => $parsed) {
                $results[] = $this->buildFromParsedImeta($parsed, [
                    'sourceType' => 'post',
                    'ownerPubkey' => $pubkey,
                    'eventId' => $eventId,
                    'kind' => 20,
                    'title' => $title,
                    'description' => $content,
                    'createdAt' => $createdAt ? (int) $createdAt : null,
                    'hashtags' => $hashtags,
                    'rawTags' => $tags,
                ]);
            }
            return $results;
        }

        // Kind 21/22 (Video): all imeta = variants of one video
        // Use the first imeta as primary, collect others as fallback/variants
        $primary = $imetaParsed[0];
        $fallbackUrls = [];
        $previewImages = [];

        // Collect all variant URLs and preview images
        foreach ($imetaParsed as $parsed) {
            $images = ImetaParser::getArray($parsed, 'image');
            $previewImages = array_merge($previewImages, $images);
            $fallbacks = ImetaParser::getArray($parsed, 'fallback');
            $fallbackUrls = array_merge($fallbackUrls, $fallbacks);
        }

        // Also check for additional fallback/image from the primary
        $media = $this->buildFromParsedImeta($primary, [
            'sourceType' => 'post',
            'ownerPubkey' => $pubkey,
            'eventId' => $eventId,
            'kind' => (int) $kind,
            'title' => $title,
            'description' => $content,
            'createdAt' => $createdAt ? (int) $createdAt : null,
            'hashtags' => $hashtags,
            'rawTags' => $tags,
        ]);

        // Merge in all collected preview images and fallbacks
        $media->previewImages = array_unique(array_merge($media->previewImages, $previewImages));
        $media->fallbackUrls = array_unique(array_merge($media->fallbackUrls, $fallbackUrls));

        return [$media];
    }

    /**
     * Normalize multiple events.
     *
     * @return NormalizedMedia[]
     */
    public function normalizeEvents(array $events): array
    {
        $results = [];
        foreach ($events as $event) {
            $results = array_merge($results, $this->normalizeEvent($event));
        }
        return $results;
    }

    /**
     * Build a NormalizedMedia from a parsed imeta multimap + event context.
     */
    private function buildFromParsedImeta(array $parsed, array $context): NormalizedMedia
    {
        return new NormalizedMedia(
            sourceType: $context['sourceType'] ?? 'post',
            ownerPubkey: $context['ownerPubkey'] ?? '',
            eventId: $context['eventId'] ?? null,
            kind: $context['kind'] ?? null,
            title: $context['title'] ?? null,
            description: $context['description'] ?? null,
            primaryUrl: ImetaParser::getString($parsed, 'url') ?? '',
            mime: ImetaParser::getString($parsed, 'm'),
            sha256: ImetaParser::getString($parsed, 'x'),
            originalSha256: ImetaParser::getString($parsed, 'ox'),
            size: ImetaParser::getInt($parsed, 'size'),
            dimensions: ImetaParser::getString($parsed, 'dim'),
            duration: ImetaParser::getFloat($parsed, 'duration'),
            bitrate: ImetaParser::getInt($parsed, 'bitrate'),
            fallbackUrls: ImetaParser::getArray($parsed, 'fallback'),
            previewImages: ImetaParser::getArray($parsed, 'image'),
            thumbUrl: ImetaParser::getString($parsed, 'thumb'),
            alt: ImetaParser::getString($parsed, 'alt'),
            blurhash: ImetaParser::getString($parsed, 'blurhash'),
            createdAt: $context['createdAt'] ?? null,
            hashtags: $context['hashtags'] ?? [],
            rawTags: $context['rawTags'] ?? [],
        );
    }

    /**
     * Get the first value for a given tag name.
     */
    private function getTagValue(array $tags, string $name): ?string
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? '') === $name && isset($tag[1])) {
                return $tag[1];
            }
        }
        return null;
    }

    /**
     * Get all values for a given tag name.
     *
     * @return string[]
     */
    private function getTagValues(array $tags, string $name): array
    {
        $values = [];
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? '') === $name && isset($tag[1])) {
                $values[] = $tag[1];
            }
        }
        return $values;
    }
}

