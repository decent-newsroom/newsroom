<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Operation;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Stage;
use App\ExpressionBundle\Runner\ClauseEvaluator;

final class NoneFilterOperation implements OperationInterface
{
    public function __construct(private readonly ClauseEvaluator $evaluator) {}

    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) {
            throw new ArityException('none requires exactly 1 input');
        }

        return array_values(array_filter(
            $inputs[0],
            fn(NormalizedItem $item) => $this->evaluator->noneMatch($stage->clauses, $item, $ctx),
        ));
    }
}

