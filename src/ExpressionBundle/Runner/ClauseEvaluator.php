<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner;

use App\ExpressionBundle\Model\Clause\ClauseInterface;
use App\ExpressionBundle\Model\Clause\CmpClause;
use App\ExpressionBundle\Model\Clause\MatchClause;
use App\ExpressionBundle\Model\Clause\NotClause;
use App\ExpressionBundle\Model\Clause\TextClause;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Parser\VariableResolver;

/**
 * Evaluates clauses against NormalizedItems with NIP-EX absence semantics.
 *
 * Runtime variables ($me, $contacts, $interests) in clause values are
 * expanded at evaluation time using VariableResolver + RuntimeContext.
 */
final class ClauseEvaluator
{
    public function __construct(
        private readonly VariableResolver $variableResolver,
    ) {}

    public function evaluate(ClauseInterface $clause, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        return match (true) {
            $clause instanceof MatchClause => $this->evalMatch($clause, $item, $ctx),
            $clause instanceof NotClause   => $this->evalNot($clause, $item, $ctx),
            $clause instanceof CmpClause   => $this->evalCmp($clause, $item, $ctx),
            $clause instanceof TextClause  => $this->evalText($clause, $item, $ctx),
            default => false,
        };
    }

    /** @param ClauseInterface[] $clauses */
    public function allMatch(array $clauses, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        foreach ($clauses as $clause) {
            if (!$this->evaluate($clause, $item, $ctx)) {
                return false;
            }
        }
        return true;
    }

    /** @param ClauseInterface[] $clauses */
    public function anyMatch(array $clauses, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        foreach ($clauses as $clause) {
            if ($this->evaluate($clause, $item, $ctx)) {
                return true;
            }
        }
        return false;
    }

    /** @param ClauseInterface[] $clauses */
    public function noneMatch(array $clauses, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        foreach ($clauses as $clause) {
            if ($this->evaluate($clause, $item, $ctx)) {
                return false;
            }
        }
        return true;
    }

    private function evalMatch(MatchClause $c, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        $resolved = $this->expandValues($c->resolvedValues, $ctx);

        if ($c->namespace === 'prop') {
            $value = $item->getProperty($c->selector);
            if ($value === null) {
                return false;
            }
            return in_array((string) $value, $resolved, true);
        }

        // tag namespace
        $values = $item->getTagValues($c->selector);
        if (empty($values)) {
            return false;
        }
        foreach ($values as $v) {
            if (in_array($v, $resolved, true)) {
                return true;
            }
        }
        return false;
    }

    private function evalNot(NotClause $c, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        $resolved = $this->expandValues($c->resolvedValues, $ctx);

        if ($c->namespace === 'prop') {
            $value = $item->getProperty($c->selector);
            if ($value === null) {
                return true;
            }
            return !in_array((string) $value, $resolved, true);
        }

        $values = $item->getTagValues($c->selector);
        if (empty($values)) {
            return true;
        }
        foreach ($values as $v) {
            if (in_array($v, $resolved, true)) {
                return false;
            }
        }
        return true;
    }

    private function evalCmp(CmpClause $c, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        $value = match ($c->namespace) {
            'prop' => $item->getProperty($c->selector),
            'tag'  => $item->getFirstTagValue($c->selector),
            ''     => $item->getDerived($c->selector),
            default => null,
        };
        if ($value === null) {
            return false;
        }

        // Resolve the comparison value (e.g. $me in a cmp clause)
        $cmpValue = $c->value;
        if ($this->variableResolver->isVariable($cmpValue)) {
            $expanded = $this->variableResolver->resolve($cmpValue, $ctx);
            $cmpValue = $expanded[0] ?? $cmpValue;
        }

        $numericProps = ['kind', 'created_at'];
        $numericDerived = ['score'];
        $isNumeric = ($c->namespace === 'prop' && in_array($c->selector, $numericProps, true))
                  || ($c->namespace === '' && in_array($c->selector, $numericDerived, true))
                  || ($c->namespace === 'tag' && is_numeric($value) && is_numeric($cmpValue));

        if ($isNumeric) {
            $a = is_numeric($value) ? (float) $value : null;
            $b = is_numeric($cmpValue) ? (float) $cmpValue : null;
            if ($a === null || $b === null) {
                return false;
            }

            return match ($c->comparator) {
                'eq'  => $a == $b,
                'neq' => $a != $b,
                'gt'  => $a > $b,
                'gte' => $a >= $b,
                'lt'  => $a < $b,
                'lte' => $a <= $b,
                default => false,
            };
        }

        // String comparison
        $a = (string) $value;
        $b = $cmpValue;
        return match ($c->comparator) {
            'eq'  => $a === $b,
            'neq' => $a !== $b,
            'gt'  => $a > $b,
            'gte' => $a >= $b,
            'lt'  => $a < $b,
            'lte' => $a <= $b,
            default => false,
        };
    }

    private function evalText(TextClause $c, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        // Resolve the search value (e.g. $me in a text clause)
        $searchValue = $c->value;
        if ($this->variableResolver->isVariable($searchValue)) {
            $expanded = $this->variableResolver->resolve($searchValue, $ctx);
            $searchValue = $expanded[0] ?? $searchValue;
        }

        $targets = [];
        if ($c->namespace === 'prop') {
            $value = $item->getProperty($c->selector);
            if ($value === null) {
                return false;
            }
            $targets = [(string) $value];
        } else {
            $targets = $item->getTagValues($c->selector);
            if (empty($targets)) {
                return false;
            }
        }

        foreach ($targets as $target) {
            $matched = match ($c->mode) {
                'contains-ci' => str_contains(mb_strtolower($target), mb_strtolower($searchValue)),
                'eq-ci'       => mb_strtolower($target) === mb_strtolower($searchValue),
                'prefix-ci'   => str_starts_with(mb_strtolower($target), mb_strtolower($searchValue)),
                default       => false,
            };
            if ($matched) {
                return true;
            }
        }
        return false;
    }

    /**
     * Expand runtime variables in a list of clause values.
     * e.g. ["$interests"] → ["bitcoin", "nostr", "music"]
     *
     * @param string[] $values Raw clause values, may contain variables
     * @return string[] Expanded values
     */
    private function expandValues(array $values, RuntimeContext $ctx): array
    {
        $expanded = [];
        foreach ($values as $value) {
            if ($this->variableResolver->isVariable($value)) {
                foreach ($this->variableResolver->resolve($value, $ctx) as $v) {
                    $expanded[] = $v;
                }
            } else {
                $expanded[] = $value;
            }
        }
        return $expanded;
    }
}

