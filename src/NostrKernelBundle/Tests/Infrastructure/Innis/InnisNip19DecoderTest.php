<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Domain\Nip19\Nip19Type;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisNip19Decoder;
use Innis\Nostr\Core\Domain\Service\Bech32EncoderInterface;
use PHPUnit\Framework\TestCase;

final class InnisNip19DecoderTest extends TestCase
{
    public function testItMapsInnisPublicKeyPayloadsToNpub(): void
    {
        $encoder = $this->createMock(Bech32EncoderInterface::class);
        $encoder->expects(self::once())
            ->method('decodeComplexEntity')
            ->with('npub1example')
            ->willReturn([
                'type' => 'pubkey',
                'pubkey' => str_repeat('a', 64),
            ]);

        $decoded = (new InnisNip19Decoder($encoder))->decode('npub1example');

        self::assertSame(Nip19Type::NPUB, $decoded->type());
        self::assertSame(['pubkey' => str_repeat('a', 64)], $decoded->data());
    }

    public function testItDistinguishesNotesFromNevents(): void
    {
        $encoder = $this->createMock(Bech32EncoderInterface::class);
        $encoder->method('decodeComplexEntity')->willReturn([
            'type' => 'event',
            'event_id' => str_repeat('b', 64),
        ]);

        $decoder = new InnisNip19Decoder($encoder);

        self::assertSame(Nip19Type::NOTE, $decoder->decode('note1example')->type());
        self::assertSame(Nip19Type::NEVENT, $decoder->decode('nevent1example')->type());
    }
}
