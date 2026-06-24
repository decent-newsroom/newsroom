<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class NostrProjectionBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__) . '/NostrProjectionBundle';
    }
}
