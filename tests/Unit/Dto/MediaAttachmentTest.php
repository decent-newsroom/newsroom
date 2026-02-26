<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\MediaAttachment;
use PHPUnit\Framework\TestCase;

class MediaAttachmentTest extends TestCase
{
    public function testGuessMimeTypeFromMp3Url(): void
    {
        $this->assertEquals('audio/mpeg', MediaAttachment::guessMimeType('https://example.com/podcast.mp3'));
    }

    public function testGuessMimeTypeFromJpegUrl(): void
    {
        $this->assertEquals('image/jpeg', MediaAttachment::guessMimeType('https://cdn.example.com/photos/image.jpeg'));
    }

    public function testGuessMimeTypeFromPngUrl(): void
    {
        $this->assertEquals('image/png', MediaAttachment::guessMimeType('https://example.com/logo.png'));
    }

    public function testGuessMimeTypeFromMp4Url(): void
    {
        $this->assertEquals('video/mp4', MediaAttachment::guessMimeType('https://example.com/video.mp4'));
    }

    public function testGuessMimeTypeFromPdfUrl(): void
    {
        $this->assertEquals('application/pdf', MediaAttachment::guessMimeType('https://example.com/document.pdf'));
    }

    public function testGuessMimeTypeStripsQueryString(): void
    {
        $this->assertEquals('audio/mpeg', MediaAttachment::guessMimeType('https://example.com/file.mp3?token=abc123&expire=999'));
    }

    public function testGuessMimeTypeStripsFragment(): void
    {
        $this->assertEquals('image/webp', MediaAttachment::guessMimeType('https://example.com/photo.webp#section'));
    }

    public function testGuessMimeTypeHandlesEncodedUrl(): void
    {
        $url = 'https://anchor.fm/s/935aecc/podcast/play/116057189/https%3A%2F%2Fd3ctxlq1ktw2nl.cloudfront.net%2Fstaging%2F2026-1-26%2F418840879-44100-2-a8fd65ad61bb9.mp3';
        $this->assertEquals('audio/mpeg', MediaAttachment::guessMimeType($url));
    }

    public function testGuessMimeTypeReturnsEmptyForUnknownExtension(): void
    {
        $this->assertEquals('', MediaAttachment::guessMimeType('https://example.com/file.xyz'));
    }

    public function testGuessMimeTypeReturnsEmptyForNoExtension(): void
    {
        $this->assertEquals('', MediaAttachment::guessMimeType('https://example.com/path/to/resource'));
    }

    public function testGuessMimeTypeReturnsEmptyForEmptyUrl(): void
    {
        $this->assertEquals('', MediaAttachment::guessMimeType(''));
    }

    public function testGuessMimeTypeCaseInsensitive(): void
    {
        $this->assertEquals('image/jpeg', MediaAttachment::guessMimeType('https://example.com/PHOTO.JPG'));
    }

    public function testToTag(): void
    {
        $attachment = new MediaAttachment('https://example.com/audio.mp3', 'audio/mpeg');
        $this->assertEquals(['imeta', 'm audio/mpeg', 'url https://example.com/audio.mp3'], $attachment->toTag());
    }

    public function testFromTag(): void
    {
        $tag = ['imeta', 'm audio/mpeg', 'url https://example.com/audio.mp3'];
        $attachment = MediaAttachment::fromTag($tag);

        $this->assertNotNull($attachment);
        $this->assertEquals('audio/mpeg', $attachment->mimeType);
        $this->assertEquals('https://example.com/audio.mp3', $attachment->url);
    }

    public function testFromTagReturnsNullForNonImetaTag(): void
    {
        $this->assertNull(MediaAttachment::fromTag(['r', 'https://example.com']));
    }

    public function testFromTagReturnsNullForEmptyTag(): void
    {
        $this->assertNull(MediaAttachment::fromTag([]));
    }

    public function testRoundTrip(): void
    {
        $original = new MediaAttachment('https://example.com/audio.mp3', 'audio/mpeg');
        $tag = $original->toTag();
        $parsed = MediaAttachment::fromTag($tag);

        $this->assertNotNull($parsed);
        $this->assertEquals($original->url, $parsed->url);
        $this->assertEquals($original->mimeType, $parsed->mimeType);
    }
}

