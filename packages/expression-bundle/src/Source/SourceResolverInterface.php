<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Source;

use DecentNewsroom\ExpressionBundle\Model\NormalizedItem;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;

interface SourceResolverInterface
{
    /**
     * @param array{0:'e'|'a',1:string} $inputRef ["e"|"a", reference]
     * @return NormalizedItem[]
     */
    public function resolve(array $inputRef, RuntimeContext $ctx): array;
}

