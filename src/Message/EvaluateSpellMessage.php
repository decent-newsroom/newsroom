<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched when a spell (kind:777) evaluation cache is cold.
 * The handler evaluates the spell, writes to cache, and publishes
 * a Mercure update so the browser can reload with cached results.
 */
final class EvaluateSpellMessage
{
    public function __construct(
        public readonly string $spellEventId,
        public readonly string $userPubkey,
        public readonly string $cacheKey,
    ) {}
}

