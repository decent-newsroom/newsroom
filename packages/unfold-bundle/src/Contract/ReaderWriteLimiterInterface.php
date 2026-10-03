<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface ReaderWriteLimiterInterface
{
    public function consume(string $readerPubkey): bool;
}
