<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Parser;

use App\Entity\Event;
use App\ExpressionBundle\Model\RuntimeContext;

/**
 * Converts a kind:777 spell event into a relay filter array.
 */
final class SpellParser
{
    public function __construct(
        private readonly TimeResolver $timeResolver,
        private readonly VariableResolver $variableResolver,
    ) {}

    public function parse(Event $spell, RuntimeContext $ctx): array
    {
        $filter = [];
        foreach ($spell->getTags() as $tag) {
            match ($tag[0] ?? null) {
                'k'       => $filter['kinds'][] = (int) $tag[1],
                'authors' => $filter['authors'] = $this->resolveAuthors(array_slice($tag, 1), $ctx),
                'ids'     => $filter['ids'] = array_slice($tag, 1),
                'tag'     => $filter['#' . $tag[1]] = array_slice($tag, 2),
                'limit'   => $filter['limit'] = (int) $tag[1],
                'since'   => $filter['since'] = $this->timeResolver->resolve($tag[1], $ctx->now),
                'until'   => $filter['until'] = $this->timeResolver->resolve($tag[1], $ctx->now),
                'search'  => $filter['search'] = $tag[1],
                'relays'  => $filter['relays'] = array_slice($tag, 1),
                default   => null,
            };
        }
        return $filter;
    }

    /** @return string[] */
    private function resolveAuthors(array $values, RuntimeContext $ctx): array
    {
        $resolved = [];
        foreach ($values as $value) {
            $expanded = $this->variableResolver->resolve($value, $ctx);
            foreach ($expanded as $v) {
                $resolved[] = $v;
            }
        }
        return array_unique($resolved);
    }
}

