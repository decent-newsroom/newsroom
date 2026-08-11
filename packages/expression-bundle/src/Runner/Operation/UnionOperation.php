<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Runner\Operation;

use DecentNewsroom\ExpressionBundle\Exception\ArityException;
use DecentNewsroom\ExpressionBundle\Model\NormalizedItem;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Model\Stage;

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

