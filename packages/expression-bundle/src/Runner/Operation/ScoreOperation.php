<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Runner\Operation;

use DecentNewsroom\ExpressionBundle\Exception\ArityException;
use DecentNewsroom\ExpressionBundle\Exception\InvalidArgumentException;
use DecentNewsroom\ExpressionBundle\Model\NormalizedItem;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Model\Stage;
use DecentNewsroom\ExpressionBundle\Runner\Normalizer\NormalizerInterface;

/**
 * NIP-FX scoring operation: compute weighted score per item from term definitions.
 */
final class ScoreOperation implements OperationInterface
{
    /** @var array<string, NormalizerInterface> */
    private array $normalizers = [];

    /** @param iterable<NormalizerInterface> $normalizerIterable */
    public function __construct(iterable $normalizerIterable)
    {
        foreach ($normalizerIterable as $normalizer) {
            $this->normalizers[$normalizer->getName()] = $normalizer;
        }
    }

    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) {
            throw new ArityException('score requires exactly 1 input');
        }

        foreach ($inputs[0] as $item) {
            $score = 0.0;
            foreach ($stage->terms as $term) {
                $normalizer = $this->normalizers[$term->normalizer] ?? null;
                if ($normalizer === null) {
                    throw new InvalidArgumentException("Unknown normalizer: {$term->normalizer}");
                }
                $termValue = $normalizer->compute($item, $term, $ctx);
                $score += $term->weight * $termValue;
            }
            $item->setScore($score);
        }

        return $inputs[0];
    }
}

