<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Parser;

use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Model\Clause\ClauseInterface;
use App\ExpressionBundle\Model\Clause\CmpClause;
use App\ExpressionBundle\Model\Clause\MatchClause;
use App\ExpressionBundle\Model\Clause\NotClause;
use App\ExpressionBundle\Model\Clause\TextClause;

/**
 * Parses clause tags into ClauseInterface objects.
 */
final class ClauseParser
{
    private const VALID_COMPARATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'];
    private const VALID_TEXT_MODES = ['contains-ci', 'eq-ci', 'prefix-ci'];

    public function parse(array $tag): ClauseInterface
    {
        $type = $tag[0] ?? null;

        return match ($type) {
            'match' => $this->parseMatch($tag),
            'not'   => $this->parseNot($tag),
            'cmp'   => $this->parseCmp($tag),
            'text'  => $this->parseText($tag),
            default => throw new InvalidArgumentException("Unknown clause type: {$type}"),
        };
    }

    private function parseMatch(array $tag): MatchClause
    {
        // ["match", namespace, selector, ...values]
        if (count($tag) < 4) {
            throw new InvalidArgumentException('match clause requires at least 4 elements');
        }
        $this->validateNamespace($tag[1], ['prop', 'tag']);
        return new MatchClause($tag[1], $tag[2], array_slice($tag, 3));
    }

    private function parseNot(array $tag): NotClause
    {
        // ["not", namespace, selector, ...values]
        if (count($tag) < 4) {
            throw new InvalidArgumentException('not clause requires at least 4 elements');
        }
        $this->validateNamespace($tag[1], ['prop', 'tag']);
        return new NotClause($tag[1], $tag[2], array_slice($tag, 3));
    }

    private function parseCmp(array $tag): CmpClause
    {
        // ["cmp", namespace, selector, comparator, value]
        if (count($tag) !== 5) {
            throw new InvalidArgumentException('cmp clause requires exactly 5 elements');
        }
        $this->validateNamespace($tag[1], ['prop', 'tag', '']);
        if (!in_array($tag[3], self::VALID_COMPARATORS, true)) {
            throw new InvalidArgumentException("Invalid comparator: {$tag[3]}");
        }
        return new CmpClause($tag[1], $tag[2], $tag[3], $tag[4]);
    }

    private function parseText(array $tag): TextClause
    {
        // ["text", namespace, selector, mode, value]
        if (count($tag) !== 5) {
            throw new InvalidArgumentException('text clause requires exactly 5 elements');
        }
        $this->validateNamespace($tag[1], ['prop', 'tag']);
        if (!in_array($tag[3], self::VALID_TEXT_MODES, true)) {
            throw new InvalidArgumentException("Invalid text mode: {$tag[3]}");
        }
        return new TextClause($tag[1], $tag[2], $tag[3], $tag[4]);
    }

    private function validateNamespace(string $ns, array $allowed): void
    {
        if (!in_array($ns, $allowed, true)) {
            throw new InvalidArgumentException("Invalid namespace: '{$ns}', expected one of: " . implode(', ', $allowed));
        }
    }
}

