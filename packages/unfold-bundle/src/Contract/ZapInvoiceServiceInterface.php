<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface ZapInvoiceServiceInterface
{
    /**
     * @return list<ZapInvoice>
     */
    public function createInvoices(ZapInvoiceRequest $request): array;
}
