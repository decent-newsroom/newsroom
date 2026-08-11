<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Runner\Operation;

use DecentNewsroom\ExpressionBundle\Exception\ArityException;
use DecentNewsroom\ExpressionBundle\Model\NormalizedItem;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Model\Stage;
use DecentNewsroom\ExpressionBundle\Runner\ClauseEvaluator;

final class AnyFilterOperation implements OperationInterface
{
    public function __construct(private readonly ClauseEvaluator $evaluator) {}

    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) {
            throw new ArityException('any requires exactly 1 input');
        }

        return array_values(array_filter(
            $inputs[0],
            fn(NormalizedItem $item) => $this->evaluator->anyMatch($stage->clauses, $item, $ctx),
        ));
    }
}

