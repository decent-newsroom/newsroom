<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Normalizer;

use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Term;

interface NormalizerInterface
{
    public function compute(NormalizedItem $item, Term $term, RuntimeContext $ctx): float;

    public function getName(): string;
}

