<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class RelayGatewayBundle extends Bundle
{
    public function getPath(): string
    {
        return __DIR__;
    }
}