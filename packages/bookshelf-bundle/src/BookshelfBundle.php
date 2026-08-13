<?php

namespace DecentNewsroom\BookshelfBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * BookshelfBundle
 *
 * Nostr-based bookshelf and e-reader.
 */
class BookshelfBundle extends Bundle
{
    public function getPath(): string
    {
        return __DIR__;
    }
}
