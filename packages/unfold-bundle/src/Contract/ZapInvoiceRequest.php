<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

final readonly class ZapInvoiceRequest
{
    /**
     * @param list<array{recipient: string, relay?: string|null, weight?: int}> $splits
     */
    public function __construct(
        public string $recipientPubkey,
        public ?string $lud16,
        public ?string $lud06,
        public int $amountSats,
        public string $comment = '',
        public array $splits = [],
    ) {
    }
}
