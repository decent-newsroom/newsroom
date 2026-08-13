<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Domain\Nip19\DecodedNip19;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\Nip19Type;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisNip19Encoder;
use Innis\Nostr\Core\Domain\Service\Bech32EncoderInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;

final class InnisNip19EncoderTest extends TestCase
{
    public function testItEncodesNpubValuesDirectly(): void
    {
        $hex = str_repeat('a', 64);
        $bech32 = (new InnisNip19Encoder($this->createMock(Bech32EncoderInterface::class)))
            ->encode(new DecodedNip19(Nip19Type::NPUB, ['pubkey' => $hex]));

        self::assertStringStartsWith('npub1', $bech32);
        self::assertSame($hex, PublicKey::fromBech32($bech32)?->toHex());
    }

    public function testItDelegatesAddressEncodingToInnis(): void
    {
        $hex = str_repeat('b', 64);
        $encoder = $this->createMock(Bech32EncoderInterface::class);
        $encoder->expects(self::once())
            ->method('encodeAddressableEvent')
            ->with('article-slug', self::callback(
                static fn (PublicKey $pubkey): bool => $pubkey->toHex() === $hex
            ), 30023, ['wss://relay.example'])
            ->willReturn('naddr1example');

        $bech32 = (new InnisNip19Encoder($encoder))->encode(new DecodedNip19(
            Nip19Type::NADDR,
            [
                'identifier' => 'article-slug',
                'pubkey' => $hex,
                'kind' => 30023,
                'relays' => ['wss://relay.example'],
            ],
        ));

        self::assertSame('naddr1example', $bech32);
    }

    public function testItRejectsUnsupportedEncodingTypes(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new InnisNip19Encoder($this->createMock(Bech32EncoderInterface::class)))
            ->encode(new DecodedNip19(Nip19Type::NOTE, ['event_id' => str_repeat('c', 64)]));
    }
}
