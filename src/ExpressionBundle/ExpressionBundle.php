<?php

namespace App\ExpressionBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * ExpressionBundle — NIP-EX + NIP-FX Expression Runner
 *
 * Self-contained bundle that parses kind:30880 feed expression events
 * into a pipeline, evaluates filter/sort/set/score operations against
 * normalized Nostr events, and exposes an authenticated API endpoint.
 */
class ExpressionBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__) . '/ExpressionBundle';
    }
}

