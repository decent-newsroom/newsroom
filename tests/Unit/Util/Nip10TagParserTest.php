<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\Nip10TagParser;
use PHPUnit\Framework\TestCase;

final class Nip10TagParserTest extends TestCase
{
    public function testReturnsNullWhenNoRootMarkerExists(): void
    {
        $tags = [
            ['e', 'reply-event-id', 'wss://relay.example', 'reply'],
            ['e', 'mention-event-id', 'wss://relay.example', 'mention'],
        ];

        $parsed = Nip10TagParser::findRootReference($tags);

        $this->assertNull($parsed);
    }

    public function testParsesRootMarkerInFourthPositionWithRelayHint(): void
    {
        $tags = [
            ['e', 'root-event-id', 'wss://relay.one', 'root'],
        ];

        $parsed = Nip10TagParser::findRootReference($tags);

        $this->assertNotNull($parsed);
        $this->assertSame('root-event-id', $parsed['eventId']);
        $this->assertSame(['wss://relay.one'], $parsed['relays']);
    }

    public function testParsesRootMarkerInThirdPositionWithRelayInFourthPosition(): void
    {
        $tags = [
            ['e', 'root-event-id', 'root', 'wss://relay.two'],
        ];

        $parsed = Nip10TagParser::findRootReference($tags);

        $this->assertNotNull($parsed);
        $this->assertSame('root-event-id', $parsed['eventId']);
        $this->assertSame(['wss://relay.two'], $parsed['relays']);
    }

    public function testSkipsMalformedTagsAndReturnsFirstValidRoot(): void
    {
        $tags = [
            ['e'],
            'not-an-array',
            ['e', '', 'wss://relay.invalid', 'root'],
            ['e', 'first-root', 'wss://relay.one', 'root'],
            ['e', 'second-root', 'wss://relay.two', 'root'],
        ];

        $parsed = Nip10TagParser::findRootReference($tags);

        $this->assertNotNull($parsed);
        $this->assertSame('first-root', $parsed['eventId']);
        $this->assertSame(['wss://relay.one'], $parsed['relays']);
    }
}

