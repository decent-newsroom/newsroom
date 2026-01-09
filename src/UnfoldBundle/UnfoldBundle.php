<?php

namespace App\UnfoldBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * UnfoldBundle — Nostr Website Rendering Runtime
 *
 * Renders websites from Nostr content by resolving subdomains to magazine naddrs,
 * fetching content from the event tree, and rendering via Handlebars templates.
 */
class UnfoldBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__) . '/UnfoldBundle';
    }
}

