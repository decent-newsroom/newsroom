<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Normalizer;

use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Term;

/**
 * Recency decay: 1/(1+(hours_since/half_life)), clamped [0,1].
 */
final class RecencyNormalizer implements NormalizerInterface
{
    public function getName(): string { return 'recency'; }

    public function compute(NormalizedItem $item, Term $term, RuntimeContext $ctx): float
    {
        $value = $this->extractNumeric($item, $term);
        if ($value === null) {
            return 0.0;
        }

        $halfLifeHours = $this->parseHalfLife($term->extraValues[0] ?? '1h');
        $hoursSince = ($ctx->now - $value) / 3600.0;
        if ($hoursSince < 0) {
            $hoursSince = 0;
        }

        $result = 1.0 / (1.0 + ($hoursSince / $halfLifeHours));
        return max(0.0, min(1.0, $result));
    }

    private function extractNumeric(NormalizedItem $item, Term $term): ?float
    {
        $value = match ($term->namespace) {
            'prop' => $item->getProperty($term->selector),
            'tag'  => $item->getFirstTagValue($term->selector),
            ''     => $item->getDerived($term->selector),
            default => null,
        };
        return is_numeric($value) ? (float) $value : null;
    }

    private function parseHalfLife(string $spec): float
    {
        $units = [
            's'  => 1 / 3600,
            'm'  => 1 / 60,
            'h'  => 1.0,
            'd'  => 24.0,
            'w'  => 168.0,
            'mo' => 720.0,
            'y'  => 8760.0,
        ];

        if (preg_match('/^(\d+(?:\.\d+)?)(s|m|h|d|w|mo|y)$/', $spec, $m)) {
            return (float) $m[1] * $units[$m[2]];
        }

        // Default: treat as hours
        return is_numeric($spec) ? (float) $spec : 1.0;
    }
}

