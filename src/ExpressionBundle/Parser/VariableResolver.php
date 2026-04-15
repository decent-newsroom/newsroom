<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Parser;

use App\ExpressionBundle\Exception\UnresolvedVariableException;
use App\ExpressionBundle\Model\RuntimeContext;

/**
 * Expands runtime variables ($me, $contacts, $interests) to resolved values.
 */
final class VariableResolver
{
    /** @return string[] Expanded values */
    public function resolve(string $value, RuntimeContext $ctx): array
    {
        if (!$this->isVariable($value)) {
            return [$value];
        }

        return match ($value) {
            '$me' => [$ctx->mePubkey],
            '$contacts' => $ctx->contacts,
            '$interests' => $ctx->interests,
            default => throw new UnresolvedVariableException("Unknown variable: {$value}"),
        };
    }

    public function isVariable(string $value): bool
    {
        return str_starts_with($value, '$');
    }
}

