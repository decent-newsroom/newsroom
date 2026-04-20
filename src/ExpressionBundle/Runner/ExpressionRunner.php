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
use Psr\Log\LoggerInterface;

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
        private readonly LoggerInterface $logger,
        private readonly int $expressionMaxExecutionTime = 30,
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
        bool $enforceTimeout = true,
    ): array {
        $startTime = microtime(true);
        $previousResult = null;
        $stageCount = count($pipeline->stages);

        $this->logger->info('Expression pipeline started', [
            'dTag' => $pipeline->dTag,
            'stages' => $stageCount,
            'user' => substr($ctx->mePubkey, 0, 12) . '…',
        ]);

        foreach ($pipeline->stages as $i => $stage) {
            $stageStart = microtime(true);

            // 1. Resolve inputs (may involve relay I/O — not counted against timeout)
            $inputs = $this->resolveInputs($stage, $previousResult, $resolver, $ctx);

            $resolveMs = round((microtime(true) - $stageStart) * 1000);
            $inputCount = array_sum(array_map('count', $inputs));

            $this->logger->debug('Stage inputs resolved', [
                'stage' => $i + 1,
                'op' => $stage->op,
                'inputSets' => count($inputs),
                'totalItems' => $inputCount,
                'resolveMs' => $resolveMs,
            ]);

            // Check timeout after source resolution (skipped when not enforced, e.g. async workers)
            if ($enforceTimeout) {
                $elapsed = microtime(true) - $startTime;
                if ($elapsed >= $this->expressionMaxExecutionTime) {
                    $this->logger->warning('Expression timeout', [
                        'dTag' => $pipeline->dTag,
                        'stage' => $i + 1,
                        'op' => $stage->op,
                        'elapsedS' => round($elapsed, 2),
                        'limitS' => $this->expressionMaxExecutionTime,
                    ]);
                    throw new TimeoutException('Expression evaluation exceeded max execution time');
                }
            }

            // 2. Get the operation
            $operation = $this->operations[$stage->op] ?? null;
            if ($operation === null) {
                $this->logger->error('Unknown operation', [
                    'op' => $stage->op,
                    'dTag' => $pipeline->dTag,
                ]);
                throw new UnknownOpException("Unknown operation: {$stage->op}");
            }

            // 3. Execute
            $previousResult = $operation->execute($inputs, $stage, $ctx);

            $stageMs = round((microtime(true) - $stageStart) * 1000);
            $this->logger->debug('Stage completed', [
                'stage' => $i + 1,
                'op' => $stage->op,
                'outputItems' => count($previousResult),
                'stageMs' => $stageMs,
            ]);
        }

        $totalMs = round((microtime(true) - $startTime) * 1000);
        $resultCount = count($previousResult ?? []);

        $this->logger->info('Expression pipeline finished', [
            'dTag' => $pipeline->dTag,
            'stages' => $stageCount,
            'resultItems' => $resultCount,
            'totalMs' => $totalMs,
        ]);

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

