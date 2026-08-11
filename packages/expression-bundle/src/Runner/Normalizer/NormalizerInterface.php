<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Runner\Normalizer;

use DecentNewsroom\ExpressionBundle\Model\NormalizedItem;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Model\Term;

interface NormalizerInterface
{
    public function compute(NormalizedItem $item, Term $term, RuntimeContext $ctx): float;

    public function getName(): string;
}

