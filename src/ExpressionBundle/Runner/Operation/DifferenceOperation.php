<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Operation;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Stage;

/**
 * Keep items from first input that are NOT in any later input (by canonical identity).
 */
final class DifferenceOperation implements OperationInterface
{
    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) < 2) {
            throw new ArityException('difference requires at least 2 inputs');
        }

        // Collect all canonical IDs from later inputs
        $exclude = [];
        for ($i = 1; $i < count($inputs); $i++) {
            foreach ($inputs[$i] as $item) {
                $exclude[$item->getCanonicalId()] = true;
            }
        }

        $result = [];
        foreach ($inputs[0] as $item) {
            if (!isset($exclude[$item->getCanonicalId()])) {
                $result[] = $item;
            }
        }
        return $result;
    }
}

