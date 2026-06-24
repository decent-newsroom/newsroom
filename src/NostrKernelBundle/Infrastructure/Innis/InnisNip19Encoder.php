<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\DecodedNip19;

final readonly class InnisNip19Encoder implements Nip19EncoderInterface
{
    public function encode(DecodedNip19 $decoded): string
    {
        // TODO(next pass): wire to innis/nostr-core NIP-19 encoder after API validation.
        throw new \LogicException('Innis NIP-19 encoder adapter is not wired yet; inspect innis/nostr-core APIs in the next pass.');
    }
}

