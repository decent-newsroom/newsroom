<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrClientBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class NostrClientBundle extends Bundle
{
    public function getPath(): string
    {
        return __DIR__;
    }
}
