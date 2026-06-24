<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\DecodedNip19;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\Nip19Type;
use Innis\Nostr\Core\Domain\Service\Bech32EncoderInterface;

final readonly class InnisNip19Decoder implements Nip19DecoderInterface
{
    public function __construct(private Bech32EncoderInterface $encoder)
    {
    }

    public function decode(string $value): DecodedNip19
    {
        $decoded = $this->encoder->decodeComplexEntity($value);

        return new DecodedNip19(
            type: Nip19Type::from($decoded['type']),
            data: $decoded['data'],
        );
    }
}

