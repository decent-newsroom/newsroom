<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched when an expression evaluation cache is cold.
 * The handler evaluates the expression, writes to cache, and publishes
 * a Mercure update so the browser can reload with cached results.
 */
final class EvaluateExpressionMessage
{
    public function __construct(
        public readonly int    $kind,
        public readonly string $pubkey,
        public readonly string $identifier,
        public readonly string $userPubkey,
        public readonly string $cacheKey,
    ) {}
}

