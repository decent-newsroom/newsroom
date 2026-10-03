<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Config;

use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use DecentNewsroom\UnfoldBundle\Config\CategoryReference;
use DecentNewsroom\UnfoldBundle\Content\ContentKindPolicy;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use PHPUnit\Framework\TestCase;
use nostriphant\NIP19\Bech32;

final class ContentReferenceTest extends TestCase
{
    public function testKindsHaveExplicitFormatsAndPreserveIdentifiers(): void
    {
        foreach ([30023 => 'markdown', 30041 => 'asciidoc', 30818 => 'asciidoc', 30817 => 'markdown'] as $kind => $format) {
            $reference = ContentReference::fromInput($kind . ':' . str_repeat('A', 64) . ':Topic:Part/Two');
            self::assertSame($kind . ':' . str_repeat('a', 64) . ':Topic:Part/Two', $reference->coordinate);
            self::assertSame($format, ContentKindPolicy::format($reference->kind));
            self::assertSame($kind, ContentKindPolicy::kindForSegment(ContentKindPolicy::segment($kind)));
        }
    }

    public function testRejectsUnsupportedKinds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentReference::fromInput('30024:' . str_repeat('a', 64) . ':draft');
    }

    public function testNaddrPreservesIdentityAndRelayHints(): void
    {
        $naddr = (string) Bech32::naddr(identifier: 'Topic:Part/Two', pubkey: str_repeat('a', 64), kind: 30817, relays: ['wss://relay.example']);
        $reference = ContentReference::fromInput('nostr:' . $naddr);

        self::assertSame('30817:' . str_repeat('a', 64) . ':Topic:Part/Two', $reference->coordinate);
        self::assertSame(['wss://relay.example'], $reference->relayHints);
    }

    public function testRawIdentifiersKeepSignificantWhitespace(): void
    {
        $pubkey = str_repeat('a', 64);
        self::assertSame(" Topic ", ContentReference::fromInput("30817:{$pubkey}: Topic ")->identifier);
        self::assertSame("30040:{$pubkey}: Category ", CategoryReference::fromInput("30040:{$pubkey}: Category ")->coordinate);
        $naddr = (string) Bech32::naddr(identifier: ' Topic ', pubkey: $pubkey, kind: 30817);
        self::assertSame(' Topic ', ContentReference::fromInput(" {$naddr} ")->identifier);
    }

    public function testInvalidNaddrIsReportedAsInputError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentReference::fromInput('naddr1malformed');
    }

    public function testRelayUsernameWithoutPasswordIsRejected(): void
    {
        $naddr = (string) Bech32::naddr(identifier: 'spec', pubkey: str_repeat('a', 64), kind: 30817, relays: ['wss://user@relay.example']);
        $this->expectException(\InvalidArgumentException::class);
        ContentReference::fromInput($naddr);
    }

    public function testRejectsMoreThanFiveRelayHints(): void
    {
        $relays = array_map(static fn(int $i): string => "wss://relay{$i}.example", range(1, 6));
        $naddr = (string) Bech32::naddr(identifier: 'spec', pubkey: str_repeat('a', 64), kind: 30817, relays: $relays);
        $this->expectException(\InvalidArgumentException::class);
        ContentReference::fromInput($naddr);
    }

    public function testDuplicateHintsDoNotConsumeAdditionalSlots(): void
    {
        $naddr = (string) Bech32::naddr(identifier: 'spec', pubkey: str_repeat('a', 64), kind: 30817, relays: ['wss://relay.example', 'wss://relay.example']);
        self::assertSame(['wss://relay.example'], ContentReference::fromInput($naddr)->relayHints);
    }

    public function testRejectsAmbiguousEventIdentifier(): void
    {
        $event = new NostrEvent('', str_repeat('a', 64), 30817, '', [['d', 'first'], ['d', 'second']], 1, '');
        $this->expectException(\InvalidArgumentException::class);
        ContentReference::fromEvent($event);
    }

    /** @dataProvider invalidCoordinates */
    public function testRejectsInvalidCoordinates(string $coordinate): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentReference::fromInput($coordinate);
    }

    public static function invalidCoordinates(): array
    {
        $prefix = '30817:' . str_repeat('a', 64) . ':';

        return [
            [$prefix],
            [$prefix . '  '],
            [$prefix . "line\nbreak"],
            [$prefix . str_repeat('x', 501)],
            ['30817:invalid:spec'],
            [$prefix . "\xFF"],
        ];
    }

    public function testExactEventIdentityAndScopeFlag(): void
    {
        $event = new NostrEvent(str_repeat('b', 64), str_repeat('a', 64), 30817, '# Protocol', [['d', 'Spec'], ['s']], 1, '');
        $reference = ContentReference::fromInput('30817:' . str_repeat('a', 64) . ':Spec');
        self::assertTrue($reference->matches($event));
        self::assertFalse(ContentReference::fromInput('30817:' . str_repeat('a', 64) . ':spec')->matches($event));
        self::assertTrue(ContentReference::isScoped($event));
    }
}
