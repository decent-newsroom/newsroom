<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\Nip22TagParser;
use PHPUnit\Framework\TestCase;

class Nip22TagParserTest extends TestCase
{
    // ─── parse() ────────────────────────────────────────────────────

    public function testTopLevelCommentOnArticle(): void
    {
        // Example from NIP-22: top-level comment on a blog post
        $tags = [
            ['A', '30023:3c9849383bdea883b0bd16fece1ed36d37e37cdde3ce43b17ea4e9192ec11289:f9347ca7', 'wss://example.relay'],
            ['K', '30023'],
            ['P', '3c9849383bdea883b0bd16fece1ed36d37e37cdde3ce43b17ea4e9192ec11289', 'wss://example.relay'],
            ['a', '30023:3c9849383bdea883b0bd16fece1ed36d37e37cdde3ce43b17ea4e9192ec11289:f9347ca7', 'wss://example.relay'],
            ['e', '5b4fc7fed15672fefe65d2426f67197b71ccc82aa0cc8a9e94f683eb78e07651', 'wss://example.relay'],
            ['k', '30023'],
            ['p', '3c9849383bdea883b0bd16fece1ed36d37e37cdde3ce43b17ea4e9192ec11289', 'wss://example.relay'],
        ];

        $parsed = Nip22TagParser::parse($tags);

        $this->assertSame('30023', $parsed['rootKind']);
        $this->assertSame('30023', $parsed['parentKind'], 'Top-level comment parent kind = article kind');
        $this->assertSame('3c9849383bdea883b0bd16fece1ed36d37e37cdde3ce43b17ea4e9192ec11289', $parsed['rootPubkey']);
        $this->assertSame(['3c9849383bdea883b0bd16fece1ed36d37e37cdde3ce43b17ea4e9192ec11289'], $parsed['parentPubkeys']);
        $this->assertSame('5b4fc7fed15672fefe65d2426f67197b71ccc82aa0cc8a9e94f683eb78e07651', $parsed['parentEventId']);
        $this->assertNotNull($parsed['rootAddress']);
        $this->assertNull($parsed['rootEventId'], 'Addressable root uses A tag, not E');
    }

    public function testReplyToComment(): void
    {
        // Example from NIP-22: reply to a comment on a NIP-94 file
        $tags = [
            ['E', '768ac8720cdeb59227cf95e98b66560ef03d8bc9a90d721779e76e68fb42f5e6', 'wss://example.relay', 'fd913cd6fa9edb8405750cd02a8bbe16e158b8676c0e69fdc27436cc4a54cc9a'],
            ['K', '1063'],
            ['P', 'fd913cd6fa9edb8405750cd02a8bbe16e158b8676c0e69fdc27436cc4a54cc9a'],
            ['e', '5c83da77af1dec6d7289834998ad7aafbd9e2191396d75ec3cc27f5a77226f36', 'wss://example.relay', '93ef2ebaaf9554661f33e79949007900bbc535d239a4c801c33a4d67d3e7f546'],
            ['k', '1111'],
            ['p', '93ef2ebaaf9554661f33e79949007900bbc535d239a4c801c33a4d67d3e7f546'],
        ];

        $parsed = Nip22TagParser::parse($tags);

        $this->assertSame('1063', $parsed['rootKind']);
        $this->assertSame('1111', $parsed['parentKind'], 'Reply to comment has parentKind 1111');
        $this->assertSame('fd913cd6fa9edb8405750cd02a8bbe16e158b8676c0e69fdc27436cc4a54cc9a', $parsed['rootPubkey']);
        $this->assertSame(['93ef2ebaaf9554661f33e79949007900bbc535d239a4c801c33a4d67d3e7f546'], $parsed['parentPubkeys']);
        $this->assertSame('768ac8720cdeb59227cf95e98b66560ef03d8bc9a90d721779e76e68fb42f5e6', $parsed['rootEventId']);
        $this->assertSame('5c83da77af1dec6d7289834998ad7aafbd9e2191396d75ec3cc27f5a77226f36', $parsed['parentEventId']);
    }

    public function testEmptyTags(): void
    {
        $parsed = Nip22TagParser::parse([]);

        $this->assertNull($parsed['rootKind']);
        $this->assertNull($parsed['parentKind']);
        $this->assertNull($parsed['rootPubkey']);
        $this->assertEmpty($parsed['parentPubkeys']);
        $this->assertNull($parsed['parentEventId']);
    }

    public function testMalformedTagsIgnored(): void
    {
        $tags = [
            ['K'],              // missing value
            'not-an-array',     // not an array
            ['p', ''],          // empty value
            ['k', '1111'],      // valid
        ];

        $parsed = Nip22TagParser::parse($tags);

        $this->assertSame('1111', $parsed['parentKind']);
        $this->assertEmpty($parsed['parentPubkeys'], 'Empty p-tag value should be ignored');
    }

    // ─── isReplyToComment() ─────────────────────────────────────────

    public function testIsReplyToCommentTrue(): void
    {
        $tags = [
            ['K', '30023'],
            ['k', '1111'],
            ['e', 'abc123'],
            ['p', 'def456'],
        ];

        $this->assertTrue(Nip22TagParser::isReplyToComment($tags));
    }

    public function testIsReplyToCommentFalseForTopLevel(): void
    {
        $tags = [
            ['K', '30023'],
            ['k', '30023'],
            ['a', '30023:pubkey:slug'],
            ['p', 'pubkey123'],
        ];

        $this->assertFalse(Nip22TagParser::isReplyToComment($tags));
    }

    // ─── collectPubkeys() ───────────────────────────────────────────

    public function testCollectPubkeysForRegularComment(): void
    {
        $tags = [
            ['K', '30023'],
            ['P', 'root-author-pk'],
            ['k', '1111'],
            ['p', 'parent-author-pk'],
        ];

        $keys = Nip22TagParser::collectPubkeys('comment-author-pk', $tags, 1111);

        $this->assertContains('comment-author-pk', $keys);
        $this->assertContains('parent-author-pk', $keys);
        // Uppercase P is NOT collected for non-zap events
        $this->assertNotContains('root-author-pk', $keys);
    }

    public function testCollectPubkeysForZap(): void
    {
        $tags = [
            ['P', 'zap-sender-pk'],
            ['p', 'zap-recipient-pk'],
        ];

        $keys = Nip22TagParser::collectPubkeys('relay-pk', $tags, 9735);

        $this->assertContains('relay-pk', $keys);
        $this->assertContains('zap-sender-pk', $keys, 'Uppercase P collected for zaps');
        $this->assertContains('zap-recipient-pk', $keys);
    }

    public function testCollectPubkeysDeduplicates(): void
    {
        $tags = [
            ['p', 'same-pk'],
            ['p', 'same-pk'],
        ];

        $keys = Nip22TagParser::collectPubkeys('same-pk', $tags);

        $this->assertCount(1, $keys);
        $this->assertSame('same-pk', $keys[0]);
    }

    public function testCollectPubkeysNullAuthor(): void
    {
        $tags = [
            ['p', 'parent-pk'],
        ];

        $keys = Nip22TagParser::collectPubkeys(null, $tags);

        $this->assertSame(['parent-pk'], $keys);
    }

    // ─── Case sensitivity ───────────────────────────────────────────

    public function testUppercaseAndLowercaseTagsNotConfused(): void
    {
        $tags = [
            ['E', 'root-event-id'],
            ['e', 'parent-event-id'],
            ['P', 'root-pubkey'],
            ['p', 'parent-pubkey'],
            ['A', '30023:root:slug'],
            ['a', '30023:parent:slug'],
            ['K', '30023'],
            ['k', '1111'],
        ];

        $parsed = Nip22TagParser::parse($tags);

        $this->assertSame('root-event-id', $parsed['rootEventId']);
        $this->assertSame('parent-event-id', $parsed['parentEventId']);
        $this->assertSame('root-pubkey', $parsed['rootPubkey']);
        $this->assertSame(['parent-pubkey'], $parsed['parentPubkeys']);
        $this->assertSame('30023:root:slug', $parsed['rootAddress']);
        $this->assertSame('30023:parent:slug', $parsed['parentAddress']);
        $this->assertSame('30023', $parsed['rootKind']);
        $this->assertSame('1111', $parsed['parentKind']);
    }
}

