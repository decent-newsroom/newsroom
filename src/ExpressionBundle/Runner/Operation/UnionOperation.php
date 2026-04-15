<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Operation;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Stage;

/**
 * Merge multiple input lists, deduplicating by canonical identity.
 */
final class UnionOperation implements OperationInterface
{
    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) < 2) {
            throw new ArityException('union requires at least 2 inputs');
        }

        $seen = [];
        $result = [];
        foreach ($inputs as $inputList) {
            foreach ($inputList as $item) {
                $canonical = $item->getCanonicalId();
                if (!isset($seen[$canonical])) {
                    $seen[$canonical] = true;
                    $result[] = $item;
                }
            }
        }
        return $result;
    }
}

