<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Unified media representation for both provider-uploaded assets and relay-published posts.
 *
 * Normalizes metadata from Blossom Blob Descriptors, NIP-96 responses,
 * and NIP-68/71 event parsing into one consistent shape.
 *
 * @see §7 of multimedia-manager spec
 */
final class NormalizedMedia
{
    /**
     * @param string       $sourceType      'asset' or 'post'
     * @param string|null  $providerId      Provider identifier (null for relay-sourced posts)
     * @param string       $ownerPubkey     Hex pubkey of the asset/post owner
     * @param string|null  $eventId         Nostr event ID (for posts)
     * @param int|null     $kind            Event kind (20, 21, 22)
     * @param string|null  $title           Title from event or provider metadata
     * @param string|null  $description     Content/description/summary
     * @param string       $primaryUrl      Primary media URL
     * @param string|null  $mime            MIME type (e.g. image/jpeg, video/mp4)
     * @param string|null  $sha256          SHA-256 hash of the (possibly transformed) file
     * @param string|null  $originalSha256  Original file hash (ox) before server transforms
     * @param int|null     $size            File size in bytes
     * @param string|null  $dimensions      Dimensions string (e.g. "1920x1080")
     * @param float|null   $duration        Duration in seconds (for video)
     * @param int|null     $bitrate         Bitrate in bps (for video)
     * @param string[]     $fallbackUrls    Fallback URLs for the media
     * @param string[]     $previewImages   Preview/poster image URLs
     * @param string|null  $thumbUrl        Thumbnail URL
     * @param string|null  $alt             Alt text / accessibility description
     * @param string|null  $blurhash        Blurhash placeholder string
     * @param int|null     $uploadedAt      Unix timestamp of upload
     * @param int|null     $createdAt       Unix timestamp of event creation
     * @param string[]     $hashtags        Hashtag strings
     * @param array        $rawTags         Raw Nostr tag arrays for preservation
     * @param array        $rawProviderMeta Raw provider-specific metadata
     */
    public function __construct(
        public string $sourceType,
        public ?string $providerId = null,
        public string $ownerPubkey = '',
        public ?string $eventId = null,
        public ?int $kind = null,
        public ?string $title = null,
        public ?string $description = null,
        public string $primaryUrl = '',
        public ?string $mime = null,
        public ?string $sha256 = null,
        public ?string $originalSha256 = null,
        public ?int $size = null,
        public ?string $dimensions = null,
        public ?float $duration = null,
        public ?int $bitrate = null,
        public array $fallbackUrls = [],
        public array $previewImages = [],
        public ?string $thumbUrl = null,
        public ?string $alt = null,
        public ?string $blurhash = null,
        public ?int $uploadedAt = null,
        public ?int $createdAt = null,
        public array $hashtags = [],
        public array $rawTags = [],
        public array $rawProviderMeta = [],
    ) {}

    /**
     * Determine if this is a picture media type.
     */
    public function isPicture(): bool
    {
        return $this->kind === 20 || ($this->mime !== null && str_starts_with($this->mime, 'image/'));
    }

    /**
     * Determine if this is a video media type.
     */
    public function isVideo(): bool
    {
        return in_array($this->kind, [21, 22, 34235, 34236], true) || ($this->mime !== null && str_starts_with($this->mime, 'video/'));
    }

    /**
     * Determine if this is a short/portrait video.
     */
    public function isShortVideo(): bool
    {
        return in_array($this->kind, [22, 34236], true);
    }

    /**
     * Get the best available preview image URL.
     *
     * For images: thumb → first preview → primaryUrl
     * For videos: first preview → thumb → null
     */
    public function getBestPreview(): ?string
    {
        if ($this->isPicture()) {
            return $this->thumbUrl ?? ($this->previewImages[0] ?? $this->primaryUrl);
        }

        // Video preview order
        return $this->previewImages[0] ?? $this->thumbUrl ?? null;
    }

    /**
     * Get the de-duplication key (SHA-256 hash, preferring original).
     */
    public function getDedupeKey(): ?string
    {
        return $this->originalSha256 ?? $this->sha256;
    }

    /**
     * Get a human-readable kind label.
     */
    public function getKindLabel(): string
    {
        return match ($this->kind) {
            20 => 'Picture',
            21, 34235 => 'Video',
            22, 34236 => 'Short Video',
            default => 'Unknown',
        };
    }

    /**
     * Serialize to array for JSON API responses.
     */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'provider_id' => $this->providerId,
            'owner_pubkey' => $this->ownerPubkey,
            'event_id' => $this->eventId,
            'kind' => $this->kind,
            'kind_label' => $this->getKindLabel(),
            'title' => $this->title,
            'description' => $this->description,
            'primary_url' => $this->primaryUrl,
            'mime' => $this->mime,
            'sha256' => $this->sha256,
            'original_sha256' => $this->originalSha256,
            'size' => $this->size,
            'dimensions' => $this->dimensions,
            'duration' => $this->duration,
            'bitrate' => $this->bitrate,
            'fallback_urls' => $this->fallbackUrls,
            'preview_images' => $this->previewImages,
            'thumb_url' => $this->thumbUrl,
            'best_preview' => $this->getBestPreview(),
            'alt' => $this->alt,
            'blurhash' => $this->blurhash,
            'uploaded_at' => $this->uploadedAt,
            'created_at' => $this->createdAt,
            'hashtags' => $this->hashtags,
        ];
    }
}

