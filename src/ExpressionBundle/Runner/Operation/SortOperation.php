<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Operation;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Stage;

/**
 * Sort operation with namespace-based field resolution, num/alpha modes,
 * and fallback chain (published_at desc → stable order).
 */
final class SortOperation implements OperationInterface
{
    private const BLOCKED_SORT_PROPS = ['id', 'pubkey'];

    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) {
            throw new ArityException('sort requires exactly 1 input');
        }

        $items = $inputs[0];
        $ns = $stage->sortNamespace;
        $field = $stage->sortField;
        $desc = $stage->sortDirection === 'desc';
        $alpha = ($stage->sortMode ?? 'num') === 'alpha';

        if ($ns === 'prop' && in_array($field, self::BLOCKED_SORT_PROPS, true)) {
            throw new InvalidArgumentException("Sorting by '{$field}' is not allowed");
        }

        usort($items, function (NormalizedItem $a, NormalizedItem $b) use ($ns, $field, $desc, $alpha) {
            // Primary sort
            $cmp = $this->compareField($a, $b, $ns, $field, $desc, $alpha);
            if ($cmp !== 0) {
                return $cmp;
            }

            // Fallback 1: published_at desc (tag, numeric)
            if (!($ns === 'tag' && $field === 'published_at')) {
                $cmp = $this->compareField($a, $b, 'tag', 'published_at', true, false);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            // Fallback 2: preserve original order (stable sort)
            return 0;
        });

        return $items;
    }

    private function compareField(NormalizedItem $a, NormalizedItem $b, string $ns, string $field, bool $desc, bool $alpha): int
    {
        $va = $this->resolveValue($a, $ns, $field);
        $vb = $this->resolveValue($b, $ns, $field);

        // Present before absent
        if ($va === null && $vb === null) {
            return 0;
        }
        if ($va === null) {
            return 1;
        }
        if ($vb === null) {
            return -1;
        }

        if ($alpha) {
            $cmp = strcasecmp((string) $va, (string) $vb);
        } else {
            $na = is_numeric($va) ? (float) $va : null;
            $nb = is_numeric($vb) ? (float) $vb : null;
            if ($na === null && $nb === null) {
                return 0;
            }
            if ($na === null) {
                return 1;
            }
            if ($nb === null) {
                return -1;
            }
            $cmp = $na <=> $nb;
        }

        return $desc ? -$cmp : $cmp;
    }

    private function resolveValue(NormalizedItem $item, string $ns, string $field): int|string|float|null
    {
        return match ($ns) {
            'prop' => $item->getProperty($field),
            'tag'  => $item->getFirstTagValue($field),
            ''     => $item->getDerived($field),
            default => null,
        };
    }
}

