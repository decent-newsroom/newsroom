<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class NostrKernelBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__) . '/NostrKernelBundle';
    }
}
