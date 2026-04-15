<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Operation;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Stage;

/**
 * Keep items present in all input lists (by canonical identity).
 */
final class IntersectOperation implements OperationInterface
{
    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) < 2) {
            throw new ArityException('intersect requires at least 2 inputs');
        }

        // Build sets of canonical IDs per input
        $sets = [];
        foreach ($inputs as $inputList) {
            $ids = [];
            foreach ($inputList as $item) {
                $ids[$item->getCanonicalId()] = true;
            }
            $sets[] = $ids;
        }

        // Keep items from first list that appear in all other lists
        $result = [];
        foreach ($inputs[0] as $item) {
            $canonical = $item->getCanonicalId();
            $inAll = true;
            for ($i = 1; $i < count($sets); $i++) {
                if (!isset($sets[$i][$canonical])) {
                    $inAll = false;
                    break;
                }
            }
            if ($inAll) {
                $result[] = $item;
            }
        }
        return $result;
    }
}

