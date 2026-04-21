<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Parser;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Exception\UnknownOpException;
use App\ExpressionBundle\Model\Stage;

/**
 * Parses a segment of tags (between two op tags) into a Stage object.
 */
final class StageParser
{
    private const VALID_OPS = ['all', 'any', 'none', 'sort', 'slice', 'distinct', 'union', 'intersect', 'difference', 'score', 'parent', 'child', 'ancestor', 'descendant'];
    private const FILTER_OPS = ['all', 'any', 'none'];
    private const SET_OPS = ['union', 'intersect', 'difference'];
    private const TRAVERSAL_OPS = ['parent', 'child', 'ancestor', 'descendant'];
    private const TRAVERSAL_MODIFIERS = [
        'ancestor' => ['root'],
        'descendant' => ['leaves'],
        'parent' => [],
        'child' => [],
    ];
    private const BLOCKED_SORT_PROPS = ['id', 'pubkey'];

    public function __construct(
        private readonly ClauseParser $clauseParser,
        private readonly TermParser $termParser,
    ) {}

    /**
     * @param string $op Operation name from the op tag
     * @param array $stageTags All tags belonging to this stage (between op tags)
     * @param bool $isFirstStage Whether this is the first stage (requires inputs)
     */
    public function parse(string $op, array $stageTags, bool $isFirstStage): Stage
    {
        if (!in_array($op, self::VALID_OPS, true)) {
            throw new UnknownOpException("Unknown operation: {$op}");
        }

        $inputs = [];
        $clauses = [];
        $terms = [];
        $sortNamespace = null;
        $sortField = null;
        $sortDirection = null;
        $sortMode = null;
        $sliceOffset = null;
        $sliceLimit = null;
        $traversalModifier = null;

        $isTraversal = in_array($op, self::TRAVERSAL_OPS, true);

        foreach ($stageTags as $tag) {
            $tagType = $tag[0] ?? null;

            // Traversal ops' modifier is inlined in the op tag and re-synthesized
            // by ExpressionParser as e.g. ["ancestor","root"] or ["descendant","leaves"].
            if ($isTraversal && $tagType === $op) {
                if (count($tag) > 2) {
                    throw new InvalidArgumentException("{$op} stage accepts at most one modifier");
                }
                if (isset($tag[1])) {
                    $allowed = self::TRAVERSAL_MODIFIERS[$op];
                    if (!in_array($tag[1], $allowed, true)) {
                        throw new InvalidArgumentException("Invalid modifier for {$op}: '{$tag[1]}'");
                    }
                    $traversalModifier = $tag[1];
                }
                continue;
            }

            switch ($tagType) {
                case 'input':
                    // ["input", "e"|"a", reference]
                    if (count($tag) < 3) {
                        throw new InvalidArgumentException('input tag requires at least 3 elements');
                    }
                    $inputs[] = [$tag[1], $tag[2]];
                    break;

                case 'match':
                case 'not':
                case 'cmp':
                case 'text':
                    $clauses[] = $this->clauseParser->parse($tag);
                    break;

                case 'term':
                    $terms[] = $this->termParser->parse($tag);
                    break;

                case 'sort':
                    // ["sort", namespace, field, direction] or ["sort", namespace, field, direction, mode]
                    if (count($tag) < 4) {
                        throw new InvalidArgumentException('sort tag requires at least 4 elements');
                    }
                    if ($sortField !== null) {
                        throw new InvalidArgumentException('sort stage must have exactly one sort tag');
                    }
                    $sortNamespace = $tag[1];
                    $sortField = $tag[2];
                    $sortDirection = $tag[3];
                    $sortMode = $tag[4] ?? null;

                    if (!in_array($sortNamespace, ['prop', 'tag', ''], true)) {
                        throw new InvalidArgumentException("Invalid sort namespace: '{$sortNamespace}'");
                    }
                    if (!in_array($sortDirection, ['asc', 'desc'], true)) {
                        throw new InvalidArgumentException("Invalid sort direction: {$sortDirection}");
                    }
                    if ($sortMode !== null && !in_array($sortMode, ['num', 'alpha'], true)) {
                        throw new InvalidArgumentException("Invalid sort mode: {$sortMode}");
                    }
                    if ($sortNamespace === 'prop' && in_array($sortField, self::BLOCKED_SORT_PROPS, true)) {
                        throw new InvalidArgumentException("Sorting by '{$sortField}' is not allowed");
                    }
                    break;

                case 'slice':
                    // ["slice", offset, limit]
                    if (count($tag) !== 3) {
                        throw new InvalidArgumentException('slice tag requires exactly 3 elements');
                    }
                    $sliceOffset = (int) $tag[1];
                    $sliceLimit = (int) $tag[2];
                    break;
            }
        }

        // Validation per op type
        if ($isFirstStage && empty($inputs)) {
            throw new ArityException('First stage must have at least one explicit input');
        }

        if (in_array($op, self::FILTER_OPS, true) && empty($clauses)) {
            throw new InvalidArgumentException("Filter operation '{$op}' requires at least one clause");
        }

        if ($op === 'sort' && $sortField === null) {
            throw new InvalidArgumentException('sort operation requires a sort tag');
        }

        if ($op === 'slice' && ($sliceOffset === null || $sliceLimit === null)) {
            throw new InvalidArgumentException('slice operation requires a slice tag');
        }

        if (in_array($op, self::SET_OPS, true) && count($inputs) < 2 && !$isFirstStage) {
            // Set ops need 2+ total inputs; if we have previous result, 1 explicit is enough
        }

        if ($op === 'score' && empty($terms)) {
            throw new InvalidArgumentException('score operation requires at least one term');
        }

        // Traversal ops are single-input: explicit inputs allowed only on the first stage (NIP-GX).
        if ($isTraversal && !$isFirstStage && !empty($inputs)) {
            throw new InvalidArgumentException("{$op} stage must not have explicit input tags (single-input op); remove the input tags to consume the previous stage result");
        }

        return new Stage(
            op: $op,
            inputs: $inputs,
            clauses: $clauses,
            terms: $terms,
            sortNamespace: $sortNamespace,
            sortField: $sortField,
            sortDirection: $sortDirection,
            sortMode: $sortMode,
            sliceOffset: $sliceOffset,
            sliceLimit: $sliceLimit,
            traversalModifier: $traversalModifier,
        );
    }
}

