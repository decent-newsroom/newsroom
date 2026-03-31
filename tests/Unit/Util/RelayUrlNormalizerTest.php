<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\RelayUrlNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the canonical relay URL normalizer.
 *
 * @see documentation/Nostr/relay-management-review.md §1
 */
class RelayUrlNormalizerTest extends TestCase
{
    // --- normalize ---

    public function testNormalizeTrimsWhitespace(): void
    {
        $this->assertSame('wss://relay.damus.io', RelayUrlNormalizer::normalize('  wss://relay.damus.io  '));
    }

    public function testNormalizeLowercases(): void
    {
        $this->assertSame('wss://relay.damus.io', RelayUrlNormalizer::normalize('wss://Relay.Damus.IO'));
    }

    public function testNormalizeStripsTrailingSlash(): void
    {
        $this->assertSame('wss://relay.damus.io', RelayUrlNormalizer::normalize('wss://relay.damus.io/'));
    }

    public function testNormalizeStripsMultipleTrailingSlashes(): void
    {
        $this->assertSame('wss://relay.damus.io', RelayUrlNormalizer::normalize('wss://relay.damus.io///'));
    }

    public function testNormalizeCombinesAllRules(): void
    {
        $this->assertSame('wss://relay.damus.io', RelayUrlNormalizer::normalize('  WSS://Relay.Damus.IO/  '));
    }

    public function testNormalizeHandlesWsScheme(): void
    {
        $this->assertSame('ws://strfry:7777', RelayUrlNormalizer::normalize('ws://strfry:7777'));
    }

    public function testNormalizeHandlesEmptyString(): void
    {
        $this->assertSame('', RelayUrlNormalizer::normalize(''));
    }

    public function testNormalizePreservesPath(): void
    {
        // Paths that are not just trailing slashes should be preserved
        $this->assertSame('wss://relay.example.com/nostr', RelayUrlNormalizer::normalize('wss://relay.example.com/nostr'));
    }

    // --- equals ---

    public function testEqualsWithIdenticalUrls(): void
    {
        $this->assertTrue(RelayUrlNormalizer::equals('wss://relay.damus.io', 'wss://relay.damus.io'));
    }

    public function testEqualsIgnoresCase(): void
    {
        $this->assertTrue(RelayUrlNormalizer::equals('wss://Relay.Damus.IO', 'wss://relay.damus.io'));
    }

    public function testEqualsIgnoresTrailingSlash(): void
    {
        $this->assertTrue(RelayUrlNormalizer::equals('wss://relay.damus.io/', 'wss://relay.damus.io'));
    }

    public function testEqualsIgnoresWhitespace(): void
    {
        $this->assertTrue(RelayUrlNormalizer::equals('  wss://relay.damus.io  ', 'wss://relay.damus.io'));
    }

    public function testEqualsDetectsDifferentUrls(): void
    {
        $this->assertFalse(RelayUrlNormalizer::equals('wss://relay.damus.io', 'wss://nos.lol'));
    }

    public function testEqualsDifferentSchemes(): void
    {
        $this->assertFalse(RelayUrlNormalizer::equals('ws://relay.damus.io', 'wss://relay.damus.io'));
    }
}

