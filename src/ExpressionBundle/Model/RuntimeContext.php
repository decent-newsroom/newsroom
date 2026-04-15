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
        public array $visitedExpressions = [],
    ) {}
}

