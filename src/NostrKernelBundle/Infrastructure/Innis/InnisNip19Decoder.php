<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\DecodedNip19;

final readonly class InnisNip19Decoder implements Nip19DecoderInterface
{
    public function decode(string $value): DecodedNip19
    {
        // TODO(next pass): wire to innis/nostr-core NIP-19 decoder after API validation.
        throw new \LogicException('Innis NIP-19 decoder adapter is not wired yet; inspect innis/nostr-core APIs in the next pass.');
    }
}

