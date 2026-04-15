<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Normalizer;

use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Term;
use App\ExpressionBundle\Parser\VariableResolver;
use App\ExpressionBundle\Source\ReferenceResolver;

/**
 * Membership test: 1.0 if any selected value is in the comparison set, else 0.0.
 * Comparison values can be literals, $variables, or kind:pk:d references.
 */
final class InNormalizer implements NormalizerInterface
{
    public function __construct(
        private readonly VariableResolver $variableResolver,
        private readonly ReferenceResolver $referenceResolver,
    ) {}

    public function getName(): string { return 'in'; }

    public function compute(NormalizedItem $item, Term $term, RuntimeContext $ctx): float
    {
        // Get item values to test
        $itemValues = match ($term->namespace) {
            'prop' => [($v = $item->getProperty($term->selector)) !== null ? (string) $v : null],
            'tag'  => $item->getTagValues($term->selector),
            ''     => [($v = $item->getDerived($term->selector)) !== null ? (string) $v : null],
            default => [],
        };
        $itemValues = array_filter($itemValues, fn($v) => $v !== null);
        if (empty($itemValues)) {
            return 0.0;
        }

        // Build comparison set from extraValues
        $comparisonSet = [];
        $domain = $term->namespace === 'prop' ? $term->selector : 'tag';

        foreach ($term->extraValues as $extraValue) {
            if ($this->variableResolver->isVariable($extraValue)) {
                $expanded = $this->variableResolver->resolve($extraValue, $ctx);
                foreach ($expanded as $v) {
                    $comparisonSet[] = $v;
                }
            } elseif ($this->isAddressReference($extraValue)) {
                try {
                    $expanded = $this->referenceResolver->resolveForDomain($extraValue, $domain);
                    foreach ($expanded as $v) {
                        $comparisonSet[] = $v;
                    }
                } catch (\Throwable) {
                    // Reference resolution failed, skip
                }
            } else {
                $comparisonSet[] = $extraValue;
            }
        }

        // Test membership
        foreach ($itemValues as $v) {
            if (in_array($v, $comparisonSet, true)) {
                return 1.0;
            }
        }
        return 0.0;
    }

    private function isAddressReference(string $value): bool
    {
        // kind:pubkey:d format
        return (bool) preg_match('/^\d+:[a-fA-F0-9]{64}:.+$/', $value);
    }
}

