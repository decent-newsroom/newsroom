<?php

declare(strict_types=1);

namespace App\Util;

use App\Dto\NormalizedMedia;

final class ImetaBuilder
{
    public static function build(NormalizedMedia $media): array
    {
        $entries = ['imeta'];
        $entries[] = 'url ' . $media->primaryUrl;

        if ($media->mime) {
            $entries[] = 'm ' . $media->mime;
        }
        if ($media->sha256) {
            $entries[] = 'x ' . $media->sha256;
        }
        if ($media->originalSha256 && $media->originalSha256 !== $media->sha256) {
            $entries[] = 'ox ' . $media->originalSha256;
        }
        if ($media->size !== null) {
            $entries[] = 'size ' . $media->size;
        }
        if ($media->dimensions) {
            $entries[] = 'dim ' . $media->dimensions;
        }
        if ($media->alt) {
            $entries[] = 'alt ' . $media->alt;
        }
        if ($media->blurhash) {
            $entries[] = 'blurhash ' . $media->blurhash;
        }
        if ($media->thumbUrl) {
            $entries[] = 'thumb ' . $media->thumbUrl;
        }
        foreach ($media->previewImages as $previewUrl) {
            $entries[] = 'image ' . $previewUrl;
        }
        foreach ($media->fallbackUrls as $fallbackUrl) {
            $entries[] = 'fallback ' . $fallbackUrl;
        }
        if ($media->duration !== null) {
            $entries[] = 'duration ' . $media->duration;
        }
        if ($media->bitrate !== null) {
            $entries[] = 'bitrate ' . $media->bitrate;
        }

        return $entries;
    }

    public static function buildAll(array $mediaItems): array
    {
        return array_map(fn(NormalizedMedia $m) => self::build($m), $mediaItems);
    }

    public static function buildEventTags(int $kind, string $title, array $mediaItems, array $options = []): array
    {
        $tags = [];
        $tags[] = ['title', $title];

        if (!empty($options['published_at'])) {
            $tags[] = ['published_at', (string) $options['published_at']];
        }
        if (!empty($options['alt'])) {
            $tags[] = ['alt', $options['alt']];
        }
        if (!empty($options['content_warning'])) {
            $tags[] = ['content-warning', $options['content_warning']];
        }
        foreach ($options['hashtags'] ?? [] as $hashtag) {
            $tags[] = ['t', str_replace('#', '', $hashtag)];
        }
        if ($options['add_client_tag'] ?? false) {
            $tags[] = ['client', 'Decent Newsroom'];
        }
        foreach ($mediaItems as $media) {
            $tags[] = self::build($media);
        }

        return $tags;
    }
}

