<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util\CommonMark;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the bare bech32 entity normalization in Converter.
 *
 * Since normalizeBareNostrEntities() is private, we test it via Reflection
 * on an isolated (mock-injected) Converter instance.
 */
class BareNostrNormalizationTest extends TestCase
{
    private \ReflectionMethod $method;
    private object $converter;

    protected function setUp(): void
    {
        $converterClass = new \ReflectionClass(\App\Util\CommonMark\Converter::class);
        // Create an instance without invoking the constructor (all deps are mocked away)
        $this->converter = $converterClass->newInstanceWithoutConstructor();
        $this->method = $converterClass->getMethod('normalizeBareNostrEntities');
        $this->method->setAccessible(true);
    }

    private function normalize(string $input): string
    {
        return $this->method->invoke($this->converter, $input);
    }

    // --- Basic normalisation ---

    public function testBareNeventGetsPrefixed(): void
    {
        $input    = "Check this out\nnevent1qqsduunanrnj9laeddyeaahmlgxa9xfrc9neyslmsy5kavfw08dcjpqkyt4zw";
        $expected = "Check this out\nnostr:nevent1qqsduunanrnj9laeddyeaahmlgxa9xfrc9neyslmsy5kavfw08dcjpqkyt4zw";
        $this->assertSame($expected, $this->normalize($input));
    }

    public function testBareNaddrGetsPrefixed(): void
    {
        $input = "naddr1qqs0example123";
        $this->assertStringStartsWith('nostr:', $this->normalize($input));
    }

    public function testBareNote1GetsPrefixed(): void
    {
        $input = "note1abc123def456";
        $this->assertStringStartsWith('nostr:', $this->normalize($input));
    }

    public function testBareNprofileGetsPrefixed(): void
    {
        $input = "nprofile1abc123";
        $this->assertStringStartsWith('nostr:', $this->normalize($input));
    }

    public function testBareNpubGetsPrefixed(): void
    {
        $input = "npub1abc123def456";
        $this->assertStringStartsWith('nostr:', $this->normalize($input));
    }

    // --- Already prefixed ---

    public function testAlreadyPrefixedIsNotDoubled(): void
    {
        $input = "nostr:nevent1qqsduunanrnj9laeddyeaahmlgxa9xfrc9neyslmsy5kavfw08dcjpqkyt4zw";
        $this->assertSame($input, $this->normalize($input));
    }

    // --- Multiple bare entities ---

    public function testMultipleBareEntitiesOnSeparateLines(): void
    {
        $input = "Story coverage\nnevent1aaa\nnevent1bbb\nnevent1ccc";
        $result = $this->normalize($input);

        $this->assertStringContainsString('nostr:nevent1aaa', $result);
        $this->assertStringContainsString('nostr:nevent1bbb', $result);
        $this->assertStringContainsString('nostr:nevent1ccc', $result);
        // No double prefix
        $this->assertStringNotContainsString('nostr:nostr:', $result);
    }

    // --- Protected regions ---

    public function testFencedCodeBlockIsNotNormalized(): void
    {
        $input = "```\nnevent1abc123\n```";
        $this->assertSame($input, $this->normalize($input));
    }

    public function testInlineCodeIsNotNormalized(): void
    {
        $input = "Use `nevent1abc123` for references.";
        $this->assertSame($input, $this->normalize($input));
    }

    // --- Inside URLs ---

    public function testInsideUrlIsNotNormalized(): void
    {
        $input = "https://example.com/nevent1abc123";
        $this->assertStringNotContainsString('nostr:', $this->normalize($input));
    }

    // --- Content without entities ---

    public function testContentWithoutEntitiesIsUnchanged(): void
    {
        $input = "This is a normal article about Anthropic.";
        $this->assertSame($input, $this->normalize($input));
    }

    // --- Real-world content from the bug report ---

    public function testRealWorldContent(): void
    {
        $input = "Anthropic Accidentally Leaks Claude Code Source Code, Issues Takedowns\nStory coverage\nnevent1qqsduunanrnj9laeddyeaahmlgxa9xfrc9neyslmsy5kavfw08dcjpqkyt4zw\nnevent1qqs8hjq2gu2sec6lnsm0szlvty9p02aq8y3ffc4q04ymhhlmg3zq6yg9qmpel";
        $result = $this->normalize($input);

        $this->assertStringContainsString('nostr:nevent1qqsduunanrnj9laeddyeaahmlgxa9xfrc9neyslmsy5kavfw08dcjpqkyt4zw', $result);
        $this->assertStringContainsString('nostr:nevent1qqs8hjq2gu2sec6lnsm0szlvty9p02aq8y3ffc4q04ymhhlmg3zq6yg9qmpel', $result);
        $this->assertStringNotContainsString('nostr:nostr:', $result);
    }
}

