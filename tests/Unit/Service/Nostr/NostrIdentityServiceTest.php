<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\NostrIdentityService;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\DecodedNip19;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\Nip19Type;
use PHPUnit\Framework\TestCase;

final class NostrIdentityServiceTest extends TestCase
{
    public function testItNormalizesHexAndNostrPrefixedIdentifiers(): void
    {
        $service = new NostrIdentityService(
            $this->createMock(Nip19DecoderInterface::class),
            $this->createMock(Nip19EncoderInterface::class),
        );

        self::assertSame(str_repeat('a', 64), $service->toHex(' NOSTR:' . str_repeat('A', 64) . ' '));
    }

    public function testItExtractsPublicKeysFromNprofileIdentifiers(): void
    {
        $decoder = $this->createMock(Nip19DecoderInterface::class);
        $decoder->expects(self::once())
            ->method('decode')
            ->with('nprofile1example')
            ->willReturn(new DecodedNip19(
                Nip19Type::NPROFILE,
                ['pubkey' => str_repeat('b', 64), 'relays' => []],
            ));

        $service = new NostrIdentityService(
            $decoder,
            $this->createMock(Nip19EncoderInterface::class),
        );

        self::assertSame(str_repeat('b', 64), $service->toHex('nostr:nprofile1example'));
    }

    public function testItRejectsMalformedPublicKeyIdentifiersBeforeDecoding(): void
    {
        $decoder = $this->createMock(Nip19DecoderInterface::class);
        $decoder->expects(self::never())->method('decode');

        $service = new NostrIdentityService(
            $decoder,
            $this->createMock(Nip19EncoderInterface::class),
        );

        $this->expectException(\InvalidArgumentException::class);

        $service->toHex('invalid-pubkey');
    }
}
