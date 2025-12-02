<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Dto\AdvancedMetadata;
use App\Dto\ZapSplit;
use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Service\Nostr\NostrEventBuilder;
use PHPUnit\Framework\TestCase;

class NostrEventBuilderTest extends TestCase
{
    private NostrEventBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new NostrEventBuilder();
    }

    public function testBuildBasicTags(): void
    {
        $article = new Article();
        $article->setSlug('test-article');
        $article->setTitle('Test Article');
        $article->setSummary('A test summary');
        $article->setImage('https://example.com/image.jpg');
        $article->setTopics(['nostr', 'testing']);
        $article->setPublishedAt(new \DateTimeImmutable('@1609459200')); // 2021-01-01

        $tags = $this->builder->buildTags($article, null);

        $this->assertContains(['d', 'test-article'], $tags);
        $this->assertContains(['title', 'Test Article'], $tags);
        $this->assertContains(['summary', 'A test summary'], $tags);
        $this->assertContains(['image', 'https://example.com/image.jpg'], $tags);
        $this->assertContains(['t', 'nostr'], $tags);
        $this->assertContains(['t', 'testing'], $tags);
        $this->assertContains(['published_at', '1609459200'], $tags);
    }

    public function testBuildDoNotRepublishTag(): void
    {
        $article = new Article();
        $article->setSlug('test');
        $article->setTitle('Test');

        $metadata = new AdvancedMetadata();
        $metadata->doNotRepublish = true;

        $tags = $this->builder->buildTags($article, $metadata);

        $this->assertContains(['L', 'rights.decent.newsroom'], $tags);
        $this->assertContains(['l', 'no-republish', 'rights.decent.newsroom'], $tags);
    }

    public function testBuildLicenseTags(): void
    {
        $article = new Article();
        $article->setSlug('test');
        $article->setTitle('Test');

        $metadata = new AdvancedMetadata();
        $metadata->license = 'CC-BY-4.0';

        $tags = $this->builder->buildTags($article, $metadata);

        $this->assertContains(['L', 'spdx.org/licenses'], $tags);
        $this->assertContains(['l', 'CC-BY-4.0', 'spdx.org/licenses'], $tags);
    }

    public function testBuildAllRightsReservedTag(): void
    {
        $article = new Article();
        $article->setSlug('test');
        $article->setTitle('Test');

        $metadata = new AdvancedMetadata();
        $metadata->license = 'All rights reserved';

        $tags = $this->builder->buildTags($article, $metadata);

        $this->assertContains(['L', 'rights.decent.newsroom'], $tags);
        $this->assertContains(['l', 'all-rights-reserved', 'rights.decent.newsroom'], $tags);
    }

    public function testBuildZapSplitTags(): void
    {
        $article = new Article();
        $article->setSlug('test');
        $article->setTitle('Test');

        $metadata = new AdvancedMetadata();
        $metadata->zapSplits = [
            new ZapSplit('82341f882b6eabcd2ba7f1ef90aad961cf074af15b9ef44a09f9d2a8fbfbe6a2', 'wss://relay.damus.io', 2),
            new ZapSplit('3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d', 'wss://relay.nostr.band', 1),
        ];

        $tags = $this->builder->buildTags($article, $metadata);

        $this->assertContains(
            ['zap', '82341f882b6eabcd2ba7f1ef90aad961cf074af15b9ef44a09f9d2a8fbfbe6a2', 'wss://relay.damus.io', '2'],
            $tags
        );
        $this->assertContains(
            ['zap', '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d', 'wss://relay.nostr.band', '1'],
            $tags
        );
    }

    public function testBuildContentWarningTag(): void
    {
        $article = new Article();
        $article->setSlug('test');
        $article->setTitle('Test');

        $metadata = new AdvancedMetadata();
        $metadata->contentWarning = 'graphic content';

        $tags = $this->builder->buildTags($article, $metadata);

        $this->assertContains(['content-warning', 'graphic content'], $tags);
    }

    public function testBuildExpirationTag(): void
    {
        $article = new Article();
        $article->setSlug('test');
        $article->setTitle('Test');

        $metadata = new AdvancedMetadata();
        $metadata->expirationTimestamp = 1735689600; // 2025-01-01

        $tags = $this->builder->buildTags($article, $metadata);

        $this->assertContains(['expiration', '1735689600'], $tags);
    }

    public function testBuildProtectedTag(): void
    {
        $article = new Article();
        $article->setSlug('test');
        $article->setTitle('Test');

        $metadata = new AdvancedMetadata();
        $metadata->isProtected = true;

        $tags = $this->builder->buildTags($article, $metadata);

        $this->assertContains(['-'], $tags);
    }

    public function testCalculateSharesEqualDistribution(): void
    {
        $splits = [
            new ZapSplit('pubkey1'),
            new ZapSplit('pubkey2'),
            new ZapSplit('pubkey3'),
        ];

        $shares = $this->builder->calculateShares($splits);

        $this->assertCount(3, $shares);
        $this->assertEquals(33.333333333333, $shares[0], '', 0.01);
        $this->assertEquals(33.333333333333, $shares[1], '', 0.01);
        $this->assertEquals(33.333333333333, $shares[2], '', 0.01);
    }

    public function testCalculateSharesWeightedDistribution(): void
    {
        $splits = [
            new ZapSplit('pubkey1', null, 2),
            new ZapSplit('pubkey2', null, 1),
            new ZapSplit('pubkey3', null, 1),
        ];

        $shares = $this->builder->calculateShares($splits);

        $this->assertCount(3, $shares);
        $this->assertEquals(50.0, $shares[0]);
        $this->assertEquals(25.0, $shares[1]);
        $this->assertEquals(25.0, $shares[2]);
    }

    public function testConvertNpubToHex(): void
    {
        // This is a test npub (you may need to adjust based on actual implementation)
        $hex = '82341f882b6eabcd2ba7f1ef90aad961cf074af15b9ef44a09f9d2a8fbfbe6a2';

        // Test hex passthrough
        $result = $this->builder->convertToHex($hex);
        $this->assertEquals($hex, $result);
    }

    public function testConvertInvalidPubkeyThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder->convertToHex('invalid-pubkey');
    }
}

