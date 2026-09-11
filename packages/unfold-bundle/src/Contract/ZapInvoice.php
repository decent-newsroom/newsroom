<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

final readonly class ZapInvoice
{
    public function __construct(
        public string $recipientPubkey,
        public int $amountSats,
        public string $bolt11,
        public string $qrSvg,
    ) {
    }
}
