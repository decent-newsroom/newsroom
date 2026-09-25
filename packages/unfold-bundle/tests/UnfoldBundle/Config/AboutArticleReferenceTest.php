<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Config;

use DecentNewsroom\UnfoldBundle\Config\AboutArticleReference;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;

final class AboutArticleReferenceTest extends TestCase
{
    public function testNormalizesBareCoordinateAndPreservesIdentifier(): void
    {
        $reference = AboutArticleReference::fromInput(' 30023:' . str_repeat('AB', 32) . ':About:Us ');
        self::assertSame('30023:' . str_repeat('ab', 32) . ':About:Us', $reference->coordinate);
        self::assertSame([], $reference->relayHints);
    }

    public function testDecodesNaddrAndRelayHints(): void
    {
        $naddr = (string) Bech32::naddr(
            kind: 30023,
            pubkey: str_repeat('a', 64),
            identifier: 'about',
            relays: ['wss://relay.example', 'wss://other.example'],
        );
        $reference = AboutArticleReference::fromInput('nostr:' . $naddr);
        self::assertSame('30023:' . str_repeat('a', 64) . ':about', $reference->coordinate);
        self::assertSame(['wss://relay.example', 'wss://other.example'], $reference->relayHints);
    }

    /** @dataProvider invalidReferences */
    public function testRejectsInvalidArticleReferences(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unfold_setup.invalid_about_article');
        AboutArticleReference::fromInput($input);
    }

    public static function invalidReferences(): iterable
    {
        yield 'empty' => [''];
        yield 'wrong kind coordinate' => ['30040:' . str_repeat('a', 64) . ':about'];
        yield 'short key' => ['30023:abc:about'];
        yield 'empty identifier' => ['30023:' . str_repeat('a', 64) . ':'];
        yield 'control character' => ["30023:" . str_repeat('a', 64) . ":bad\nid"];
        yield 'wrong kind naddr' => [(string) Bech32::naddr(kind: 30040, pubkey: str_repeat('a', 64), identifier: 'about')];
    }
}
