<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\GatewayConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GatewayConnection — key building, normalization.
 *
 * @see documentation/Nostr/relay-management-review.md §9
 */
class GatewayConnectionTest extends TestCase
{
    // --- buildKey: shared (no pubkey) ---

    public function testBuildKeySharedBasic(): void
    {
        $key = GatewayConnection::buildKey('wss://relay.damus.io');
        $this->assertSame('wss://relay.damus.io', $key);
    }

    public function testBuildKeySharedNormalizesTrailingSlash(): void
    {
        $a = GatewayConnection::buildKey('wss://relay.damus.io/');
        $b = GatewayConnection::buildKey('wss://relay.damus.io');
        $this->assertSame($a, $b);
    }

    public function testBuildKeySharedNormalizesCase(): void
    {
        $a = GatewayConnection::buildKey('wss://Relay.Damus.IO');
        $b = GatewayConnection::buildKey('wss://relay.damus.io');
        $this->assertSame($a, $b);
    }

    public function testBuildKeySharedNormalizesWhitespace(): void
    {
        $a = GatewayConnection::buildKey('  wss://relay.damus.io  ');
        $b = GatewayConnection::buildKey('wss://relay.damus.io');
        $this->assertSame($a, $b);
    }

    // --- buildKey: user-specific (with pubkey) ---

    public function testBuildKeyUserSpecific(): void
    {
        $key = GatewayConnection::buildKey('wss://relay.damus.io', 'abcdef1234567890');
        $this->assertSame('wss://relay.damus.io::abcdef1234567890', $key);
    }

    public function testBuildKeyUserSpecificNormalizesUrl(): void
    {
        $a = GatewayConnection::buildKey('wss://Relay.Damus.IO/', 'abc123');
        $b = GatewayConnection::buildKey('wss://relay.damus.io', 'abc123');
        $this->assertSame($a, $b);
    }

    public function testBuildKeySharedAndUserAreDifferent(): void
    {
        $shared = GatewayConnection::buildKey('wss://relay.damus.io');
        $user = GatewayConnection::buildKey('wss://relay.damus.io', 'abc123');
        $this->assertNotSame($shared, $user);
    }

    // --- GatewayConnection instance ---

    public function testIsSharedWhenNoPubkey(): void
    {
        $conn = new GatewayConnection('wss://relay.damus.io', null);
        $this->assertTrue($conn->isShared());
        $this->assertFalse($conn->isUserConnection());
    }

    public function testIsUserConnectionWhenPubkeyPresent(): void
    {
        $conn = new GatewayConnection('wss://relay.damus.io', 'abc123');
        $this->assertFalse($conn->isShared());
        $this->assertTrue($conn->isUserConnection());
    }

    public function testGetKeyMatchesBuildKey(): void
    {
        $conn = new GatewayConnection('wss://relay.damus.io', 'abc123');
        $expected = GatewayConnection::buildKey('wss://relay.damus.io', 'abc123');
        $this->assertSame($expected, $conn->getKey());
    }

    public function testAuthStatusDefaultsToNone(): void
    {
        $conn = new GatewayConnection('wss://relay.damus.io', null);
        $this->assertSame('none', $conn->authStatus);
        $this->assertFalse($conn->isAuthenticated());
    }

    public function testIsAuthenticatedWhenAuthed(): void
    {
        $conn = new GatewayConnection('wss://relay.damus.io', null);
        $conn->authStatus = 'authed';
        $this->assertTrue($conn->isAuthenticated());
    }

    public function testTouchUpdatesLastActivity(): void
    {
        $conn = new GatewayConnection('wss://relay.damus.io', null);
        $before = $conn->lastActivity;
        sleep(1); // Ensure time passes (1s granularity)
        $conn->touch();
        $this->assertGreaterThanOrEqual($before, $conn->lastActivity);
    }

    public function testReconnectDelayExponentialBackoff(): void
    {
        $conn = new GatewayConnection('wss://relay.damus.io', null);

        $conn->reconnectAttempts = 0;
        $this->assertSame(5, $conn->getReconnectDelay());

        $conn->reconnectAttempts = 1;
        $this->assertSame(10, $conn->getReconnectDelay());

        $conn->reconnectAttempts = 2;
        $this->assertSame(20, $conn->getReconnectDelay());

        // Should cap at 300
        $conn->reconnectAttempts = 10;
        $this->assertSame(300, $conn->getReconnectDelay());
    }
}

