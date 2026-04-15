<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Parser;

use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Model\Term;

/**
 * Parses NIP-FX term tags into Term objects.
 */
final class TermParser
{
    private const VALID_NORMALIZERS = ['identity', 'recency', 'log', 'in', 'contains-ci', 'count'];

    /**
     * Parse a term tag: ["term", namespace, selector, normalizer, weight, ...extraValues]
     */
    public function parse(array $tag): Term
    {
        if (count($tag) < 5) {
            throw new InvalidArgumentException('term tag requires at least 5 elements');
        }

        $namespace = $tag[1];
        if (!in_array($namespace, ['prop', 'tag', ''], true)) {
            throw new InvalidArgumentException("Invalid term namespace: '{$namespace}'");
        }

        $selector = $tag[2];
        $normalizer = $tag[3];
        if (!in_array($normalizer, self::VALID_NORMALIZERS, true)) {
            throw new InvalidArgumentException("Invalid normalizer: {$normalizer}");
        }

        $weight = (float) $tag[4];
        if ($weight < -1000 || $weight > 1000) {
            throw new InvalidArgumentException("Term weight must be between -1000 and 1000, got {$weight}");
        }

        $extraValues = array_slice($tag, 5);

        return new Term($namespace, $selector, $normalizer, $weight, $extraValues);
    }
}

