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

/**
 * Evaluates clauses against NormalizedItems with NIP-EX absence semantics.
 */
final class ClauseEvaluator
{
    public function evaluate(ClauseInterface $clause, NormalizedItem $item, RuntimeContext $ctx): bool
    {
        return match (true) {
            $clause instanceof MatchClause => $this->evalMatch($clause, $item),
            $clause instanceof NotClause   => $this->evalNot($clause, $item),
            $clause instanceof CmpClause   => $this->evalCmp($clause, $item),
            $clause instanceof TextClause  => $this->evalText($clause, $item),
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

    private function evalMatch(MatchClause $c, NormalizedItem $item): bool
    {
        if ($c->namespace === 'prop') {
            $value = $item->getProperty($c->selector);
            if ($value === null) {
                return false;
            }
            return in_array((string) $value, $c->resolvedValues, true);
        }

        // tag namespace
        $values = $item->getTagValues($c->selector);
        if (empty($values)) {
            return false;
        }
        foreach ($values as $v) {
            if (in_array($v, $c->resolvedValues, true)) {
                return true;
            }
        }
        return false;
    }

    private function evalNot(NotClause $c, NormalizedItem $item): bool
    {
        if ($c->namespace === 'prop') {
            $value = $item->getProperty($c->selector);
            if ($value === null) {
                return true;
            }
            return !in_array((string) $value, $c->resolvedValues, true);
        }

        $values = $item->getTagValues($c->selector);
        if (empty($values)) {
            return true;
        }
        foreach ($values as $v) {
            if (in_array($v, $c->resolvedValues, true)) {
                return false;
            }
        }
        return true;
    }

    private function evalCmp(CmpClause $c, NormalizedItem $item): bool
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

        $numericProps = ['kind', 'created_at'];
        $numericDerived = ['score'];
        $isNumeric = ($c->namespace === 'prop' && in_array($c->selector, $numericProps, true))
                  || ($c->namespace === '' && in_array($c->selector, $numericDerived, true))
                  || ($c->namespace === 'tag' && is_numeric($value) && is_numeric($c->value));

        if ($isNumeric) {
            $a = is_numeric($value) ? (float) $value : null;
            $b = is_numeric($c->value) ? (float) $c->value : null;
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
        $b = $c->value;
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

    private function evalText(TextClause $c, NormalizedItem $item): bool
    {
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
                'contains-ci' => str_contains(mb_strtolower($target), mb_strtolower($c->value)),
                'eq-ci'       => mb_strtolower($target) === mb_strtolower($c->value),
                'prefix-ci'   => str_starts_with(mb_strtolower($target), mb_strtolower($c->value)),
                default       => false,
            };
            if ($matched) {
                return true;
            }
        }
        return false;
    }
}

