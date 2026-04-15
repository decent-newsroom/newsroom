<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Exception\TimeoutException;
use App\ExpressionBundle\Exception\UnknownOpException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\Pipeline;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Stage;
use App\ExpressionBundle\Runner\Operation\OperationInterface;
use App\ExpressionBundle\Source\SourceResolverInterface;

/**
 * Pipeline orchestrator: resolves inputs → applies operations → produces NormalizedItem[].
 */
final class ExpressionRunner
{
    private const SET_OPS = ['union', 'intersect', 'difference'];

    /** @var array<string, OperationInterface> */
    private array $operations = [];

    /** @param iterable<OperationInterface> $operationIterable */
    public function __construct(
        iterable $operationIterable,
        private readonly int $expressionMaxExecutionTime = 10,
    ) {
        foreach ($operationIterable as $operation) {
            // Derive name from class: AllFilterOperation → "all", SortOperation → "sort"
            $name = $this->deriveOpName($operation);
            $this->operations[$name] = $operation;
        }
    }

    /**
     * @return NormalizedItem[]
     */
    public function run(
        Pipeline $pipeline,
        RuntimeContext $ctx,
        SourceResolverInterface $resolver,
    ): array {
        $startTime = time();
        $previousResult = null;

        foreach ($pipeline->stages as $stage) {
            // Check timeout
            if ((time() - $startTime) >= $this->expressionMaxExecutionTime) {
                throw new TimeoutException('Expression evaluation exceeded max execution time');
            }

            // 1. Resolve inputs
            $inputs = $this->resolveInputs($stage, $previousResult, $resolver, $ctx);

            // 2. Get the operation
            $operation = $this->operations[$stage->op] ?? null;
            if ($operation === null) {
                throw new UnknownOpException("Unknown operation: {$stage->op}");
            }

            // 3. Execute
            $previousResult = $operation->execute($inputs, $stage, $ctx);
        }

        return $previousResult ?? [];
    }

    /** @return NormalizedItem[][] */
    private function resolveInputs(
        Stage $stage,
        ?array $previousResult,
        SourceResolverInterface $resolver,
        RuntimeContext $ctx,
    ): array {
        $isSetOp = in_array($stage->op, self::SET_OPS, true);
        $hasExplicit = !empty($stage->inputs);

        if ($previousResult === null && !$hasExplicit) {
            throw new ArityException('First stage must have explicit inputs');
        }

        if (!$hasExplicit) {
            return [$previousResult];
        }

        $explicitInputs = array_map(
            fn(array $ref) => $resolver->resolve($ref, $ctx),
            $stage->inputs,
        );

        if ($isSetOp && $previousResult !== null) {
            array_unshift($explicitInputs, $previousResult);
            return $explicitInputs;
        }

        if ($hasExplicit) {
            // For non-set ops with explicit inputs: merge all explicit lists into one
            $merged = [];
            foreach ($explicitInputs as $list) {
                foreach ($list as $item) {
                    $merged[] = $item;
                }
            }
            return [$merged];
        }

        return [$previousResult];
    }

    private function deriveOpName(OperationInterface $op): string
    {
        $class = (new \ReflectionClass($op))->getShortName();
        // AllFilterOperation → all, SortOperation → sort, ScoreOperation → score, etc.
        $name = preg_replace('/(Filter)?Operation$/', '', $class);
        return lcfirst($name);
    }
}

