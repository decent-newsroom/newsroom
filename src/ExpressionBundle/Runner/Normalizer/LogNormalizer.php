<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Normalizer;

use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Term;

/**
 * Logarithmic: sign(v)*log10(1+abs(v)).
 */
final class LogNormalizer implements NormalizerInterface
{
    public function getName(): string { return 'log'; }

    public function compute(NormalizedItem $item, Term $term, RuntimeContext $ctx): float
    {
        $value = match ($term->namespace) {
            'prop' => $item->getProperty($term->selector),
            'tag'  => $item->getFirstTagValue($term->selector),
            ''     => $item->getDerived($term->selector),
            default => null,
        };

        if (!is_numeric($value)) {
            return 0.0;
        }

        $v = (float) $value;
        $sign = $v >= 0 ? 1.0 : -1.0;
        return $sign * log10(1 + abs($v));
    }
}

