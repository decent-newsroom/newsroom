<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Runner\Operation;

use DecentNewsroom\ExpressionBundle\Exception\ArityException;
use DecentNewsroom\ExpressionBundle\Model\NormalizedItem;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Model\Stage;

final class SliceOperation implements OperationInterface
{
    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) {
            throw new ArityException('slice requires exactly 1 input');
        }

        return array_slice($inputs[0], $stage->sliceOffset ?? 0, $stage->sliceLimit);
    }
}

