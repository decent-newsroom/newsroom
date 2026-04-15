<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Operation;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Stage;

/**
 * Deduplicate items by canonical identity.
 */
final class DistinctOperation implements OperationInterface
{
    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) {
            throw new ArityException('distinct requires exactly 1 input');
        }

        $seen = [];
        $result = [];
        foreach ($inputs[0] as $item) {
            $canonical = $item->getCanonicalId();
            if (!isset($seen[$canonical])) {
                $seen[$canonical] = true;
                $result[] = $item;
            }
        }
        return $result;
    }
}

