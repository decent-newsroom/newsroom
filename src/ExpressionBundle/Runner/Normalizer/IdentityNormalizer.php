<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Normalizer;

use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Term;

/**
 * Pass-through numeric value (or 0).
 */
final class IdentityNormalizer implements NormalizerInterface
{
    public function getName(): string { return 'identity'; }

    public function compute(NormalizedItem $item, Term $term, RuntimeContext $ctx): float
    {
        $value = $this->extractValue($item, $term);
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function extractValue(NormalizedItem $item, Term $term): int|string|float|null
    {
        return match ($term->namespace) {
            'prop' => $item->getProperty($term->selector),
            'tag'  => $item->getFirstTagValue($term->selector),
            ''     => $item->getDerived($term->selector),
            default => null,
        };
    }
}

