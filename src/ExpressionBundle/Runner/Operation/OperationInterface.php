<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Operation;

use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Stage;

/**
 * Contract for all pipeline operations.
 */
interface OperationInterface
{
    /**
     * @param NormalizedItem[][] $inputs One or more input lists
     * @param Stage $stage The stage configuration
     * @param RuntimeContext $ctx Runtime context
     * @return NormalizedItem[] Result list
     */
    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array;
}

