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

    /** @dataProvider mixedCaseWebSocketSchemes */
    public function testParsesMixedCaseRelaySchemeInEitherMarkerLayout(string $relay, bool $markerFirst): void
    {
        $tag = $markerFirst
            ? ['e', 'root-event-id', 'RoOt', $relay]
            : ['e', 'root-event-id', $relay, 'rOoT'];

        self::assertSame(
            ['eventId' => 'root-event-id', 'relays' => [$relay]],
            Nip10TagParser::findRootReference([$tag]),
        );
    }

    /** @return array<string, array{string, bool}> */
    public static function mixedCaseWebSocketSchemes(): array
    {
        return [
            'uppercase secure scheme before marker' => ['WSS://Relay.Example/Path', false],
            'uppercase secure scheme after marker' => ['WSS://Relay.Example/Path', true],
            'uppercase scheme before marker' => ['WS://Relay.Example', false],
            'uppercase scheme after marker' => ['WS://Relay.Example', true],
            'mixed secure scheme' => ['WsS://Relay.Example', false],
            'mixed scheme' => ['wS://Relay.Example', true],
        ];
    }

    public function testMixedCaseRelayHintsDoNotChangeFirstRootSelection(): void
    {
        $tags = [
            ['e', 'reply-id', 'WSS://Relay.Reply', 'reply'],
            ['e', 'first-root', 'ROOT', 'WsS://Relay.First'],
            ['e', 'second-root', 'WS://Relay.Second', 'root'],
        ];

        self::assertSame(
            ['eventId' => 'first-root', 'relays' => ['WsS://Relay.First']],
            Nip10TagParser::findRootReference($tags),
        );
    }

    public function testWebSocketSchemeCandidateDoesNotClaimUrlValidation(): void
    {
        self::assertTrue(Nip10TagParser::isWebSocketSchemeCandidate('WSS://'));
        self::assertTrue(Nip10TagParser::isWebSocketSchemeCandidate('wS://relay.example'));
        self::assertFalse(Nip10TagParser::isWebSocketSchemeCandidate('https://relay.example'));
        self::assertFalse(Nip10TagParser::isWebSocketSchemeCandidate('WSSX://relay.example'));
        self::assertFalse(Nip10TagParser::isWebSocketSchemeCandidate(' WSS://relay.example'));
    }
}
