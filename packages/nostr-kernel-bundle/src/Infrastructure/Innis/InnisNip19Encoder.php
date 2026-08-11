<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\DecodedNip19;
use Innis\Nostr\Core\Domain\Service\Bech32EncoderInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

final readonly class InnisNip19Encoder implements Nip19EncoderInterface
{
    public function __construct(private Bech32EncoderInterface $encoder)
    {
    }

    public function encode(DecodedNip19 $decoded): string
    {
        return $this->encoder->encodeAddressableEvent(
            $decoded->data()['identifier'] ?? '',
            PublicKey::fromHex($decoded->data()['pubkey'] ?? ''),
            $decoded->data()['kind'] ?? 0,
            $decoded->data()['relays'] ?? []
        );
    }
}

