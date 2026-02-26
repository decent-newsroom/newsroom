<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\NostrEventParser;
use PHPUnit\Framework\TestCase;

class NostrEventParserTest extends TestCase
{
    private NostrEventParser $parser;

    protected function setUp(): void
    {
        $this->parser = new NostrEventParser();
    }

    public function testParseDoNotRepublishTag(): void
    {
        $tags = [
            ['L', 'rights.decent.newsroom'],
            ['l', 'no-republish', 'rights.decent.newsroom'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        $this->assertTrue($metadata->doNotRepublish);
    }

    public function testParseLicenseTag(): void
    {
        $tags = [
            ['L', 'spdx.org/licenses'],
            ['l', 'CC-BY-4.0', 'spdx.org/licenses'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        $this->assertEquals('CC-BY-4.0', $metadata->license);
    }

    public function testParseAllRightsReservedTag(): void
    {
        $tags = [
            ['L', 'rights.decent.newsroom'],
            ['l', 'all-rights-reserved', 'rights.decent.newsroom'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        $this->assertEquals('All rights reserved', $metadata->license);
    }

    public function testParseZapSplitTags(): void
    {
        $tags = [
            ['zap', '82341f882b6eabcd2ba7f1ef90aad961cf074af15b9ef44a09f9d2a8fbfbe6a2', 'wss://relay.damus.io', '2'],
            ['zap', '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d', 'wss://relay.nostr.band', '1'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        $this->assertCount(2, $metadata->zapSplits);
        $this->assertEquals('wss://relay.damus.io', $metadata->zapSplits[0]->relay);
        $this->assertEquals(2, $metadata->zapSplits[0]->weight);
        $this->assertEquals('wss://relay.nostr.band', $metadata->zapSplits[1]->relay);
        $this->assertEquals(1, $metadata->zapSplits[1]->weight);
    }

    public function testParseContentWarningTag(): void
    {
        $tags = [
            ['content-warning', 'graphic content'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        $this->assertEquals('graphic content', $metadata->contentWarning);
    }

    public function testParseExpirationTag(): void
    {
        $tags = [
            ['expiration', '1735689600'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        $this->assertEquals(1735689600, $metadata->expirationTimestamp);
    }

    public function testParseProtectedTag(): void
    {
        $tags = [
            ['-'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        $this->assertTrue($metadata->isProtected);
    }

    public function testParseMultipleAdvancedTags(): void
    {
        $tags = [
            ['d', 'test-article'],
            ['title', 'Test Article'],
            ['L', 'rights.decent.newsroom'],
            ['l', 'no-republish', 'rights.decent.newsroom'],
            ['L', 'spdx.org/licenses'],
            ['l', 'CC-BY-NC-ND-4.0', 'spdx.org/licenses'],
            ['zap', '82341f882b6eabcd2ba7f1ef90aad961cf074af15b9ef44a09f9d2a8fbfbe6a2', 'wss://relay.damus.io', '2'],
            ['zap', '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d', '', '1'],
            ['content-warning', 'spoilers'],
            ['expiration', '1735689600'],
            ['-'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        $this->assertTrue($metadata->doNotRepublish);
        $this->assertEquals('CC-BY-NC-ND-4.0', $metadata->license);
        $this->assertCount(2, $metadata->zapSplits);
        $this->assertEquals('spoilers', $metadata->contentWarning);
        $this->assertEquals(1735689600, $metadata->expirationTimestamp);
        $this->assertTrue($metadata->isProtected);
    }

    public function testExtractArticleData(): void
    {
        $tags = [
            ['d', 'my-article-slug'],
            ['title', 'My Article Title'],
            ['summary', 'A brief summary'],
            ['image', 'https://example.com/image.jpg'],
            ['published_at', '1609459200'],
            ['t', 'nostr'],
            ['t', 'testing'],
        ];

        $data = $this->parser->extractArticleData($tags);

        $this->assertEquals('my-article-slug', $data['slug']);
        $this->assertEquals('My Article Title', $data['title']);
        $this->assertEquals('A brief summary', $data['summary']);
        $this->assertEquals('https://example.com/image.jpg', $data['image']);
        $this->assertCount(2, $data['topics']);
        $this->assertContains('nostr', $data['topics']);
        $this->assertContains('testing', $data['topics']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $data['publishedAt']);
    }

    public function testPreserveUnknownTags(): void
    {
        $tags = [
            ['d', 'test'],
            ['custom-tag', 'custom-value'],
            ['another-unknown', 'value1', 'value2'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        // Unknown tags should be preserved in extraTags
        $this->assertCount(2, $metadata->extraTags);
    }

    public function testParseSourceTags(): void
    {
        $tags = [
            ['r', 'https://example.com/source-article'],
            ['r', 'https://another.example.com/reference'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        $this->assertCount(2, $metadata->sources);
        $this->assertEquals('https://example.com/source-article', $metadata->sources[0]);
        $this->assertEquals('https://another.example.com/reference', $metadata->sources[1]);
    }

    public function testParseImetaTags(): void
    {
        $tags = [
            ['imeta', 'm audio/mpeg', 'url https://anchor.fm/s/935aecc/podcast/play/116057189/https%3A%2F%2Fd3ctxlq1ktw2nl.cloudfront.net%2Fstaging%2F2026-1-26%2F418840879-44100-2-a8fd65ad61bb9.mp3'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        $this->assertCount(1, $metadata->mediaAttachments);
        $this->assertEquals('audio/mpeg', $metadata->mediaAttachments[0]->mimeType);
        $this->assertEquals(
            'https://anchor.fm/s/935aecc/podcast/play/116057189/https%3A%2F%2Fd3ctxlq1ktw2nl.cloudfront.net%2Fstaging%2F2026-1-26%2F418840879-44100-2-a8fd65ad61bb9.mp3',
            $metadata->mediaAttachments[0]->url
        );
    }

    public function testParseMultipleImetaTags(): void
    {
        $tags = [
            ['imeta', 'm audio/mpeg', 'url https://example.com/audio.mp3'],
            ['imeta', 'm image/png', 'url https://example.com/image.png'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        $this->assertCount(2, $metadata->mediaAttachments);
        $this->assertEquals('audio/mpeg', $metadata->mediaAttachments[0]->mimeType);
        $this->assertEquals('https://example.com/audio.mp3', $metadata->mediaAttachments[0]->url);
        $this->assertEquals('image/png', $metadata->mediaAttachments[1]->mimeType);
        $this->assertEquals('https://example.com/image.png', $metadata->mediaAttachments[1]->url);
    }

    public function testSourceAndImetaTagsNotInExtraTags(): void
    {
        $tags = [
            ['r', 'https://example.com/source'],
            ['imeta', 'm audio/mpeg', 'url https://example.com/audio.mp3'],
            ['custom-tag', 'custom-value'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        // r and imeta should NOT end up in extraTags
        $this->assertCount(1, $metadata->extraTags);
        $this->assertEquals('custom-tag', $metadata->extraTags[0][0]);

        // They should be in their proper fields
        $this->assertCount(1, $metadata->sources);
        $this->assertCount(1, $metadata->mediaAttachments);
    }

    public function testRoundTripSourcesAndImeta(): void
    {
        $tags = [
            ['d', 'test-article'],
            ['title', 'Test Article'],
            ['L', 'spdx.org/licenses'],
            ['l', 'CC-BY-4.0', 'spdx.org/licenses'],
            ['r', 'https://example.com/source'],
            ['imeta', 'm audio/mpeg', 'url https://example.com/podcast.mp3'],
            ['content-warning', 'spoilers'],
        ];

        $metadata = $this->parser->parseAdvancedMetadata($tags);

        $this->assertEquals('CC-BY-4.0', $metadata->license);
        $this->assertCount(1, $metadata->sources);
        $this->assertEquals('https://example.com/source', $metadata->sources[0]);
        $this->assertCount(1, $metadata->mediaAttachments);
        $this->assertEquals('audio/mpeg', $metadata->mediaAttachments[0]->mimeType);
        $this->assertEquals('https://example.com/podcast.mp3', $metadata->mediaAttachments[0]->url);
        $this->assertEquals('spoilers', $metadata->contentWarning);
    }
}

