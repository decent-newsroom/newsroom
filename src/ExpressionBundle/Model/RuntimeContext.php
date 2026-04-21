<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Model;

/**
 * Runtime context for expression evaluation.
 * Always constructed from an authenticated user.
 */
final class RuntimeContext
{
    public function __construct(
        public readonly string $mePubkey,
        public readonly array $contacts,
        public readonly array $interests,
        public readonly int $now,
        /**
         * User's declared read/CONTENT relays (NIP-65 kind:10002).
         * Used to broaden fanout for spell evaluation and other source
         * resolution so results are not limited to the local DB / default
         * content relays.
         *
         * @var string[]
         */
        public readonly array $relays = [],
        public array $visitedExpressions = [],
    ) {}
}

