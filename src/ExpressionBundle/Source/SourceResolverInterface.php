<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;

interface SourceResolverInterface
{
    /**
     * @param array $inputRef ["e"|"a", reference]
     * @return NormalizedItem[]
     */
    public function resolve(array $inputRef, RuntimeContext $ctx): array;
}

