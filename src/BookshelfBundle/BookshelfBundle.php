<?php

namespace DecentNewsroom\BookshelfBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * BookshelfBundle
 *
 * TODO: describe what this bundle does.
 */
class BookshelfBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__) . '/BookshelfBundle';
    }
}
