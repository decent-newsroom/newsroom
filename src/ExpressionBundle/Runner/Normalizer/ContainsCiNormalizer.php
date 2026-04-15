<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Normalizer;

use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Term;

/**
 * Case-insensitive substring match: 1.0 if any selected value contains any search string, else 0.0.
 */
final class ContainsCiNormalizer implements NormalizerInterface
{
    public function getName(): string { return 'contains-ci'; }

    public function compute(NormalizedItem $item, Term $term, RuntimeContext $ctx): float
    {
        $values = match ($term->namespace) {
            'prop' => [($v = $item->getProperty($term->selector)) !== null ? (string) $v : null],
            'tag'  => $item->getTagValues($term->selector),
            ''     => [($v = $item->getDerived($term->selector)) !== null ? (string) $v : null],
            default => [],
        };
        $values = array_filter($values, fn($v) => $v !== null);
        if (empty($values)) {
            return 0.0;
        }

        foreach ($values as $value) {
            foreach ($term->extraValues as $search) {
                if (str_contains(mb_strtolower($value), mb_strtolower($search))) {
                    return 1.0;
                }
            }
        }
        return 0.0;
    }
}

