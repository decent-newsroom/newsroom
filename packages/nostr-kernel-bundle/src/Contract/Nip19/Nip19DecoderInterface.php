<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Contract\Nip19;

use DecentNewsroom\NostrKernelBundle\Domain\Nip19\DecodedNip19;

interface Nip19DecoderInterface
{
    public function decode(string $value): DecodedNip19;
}

