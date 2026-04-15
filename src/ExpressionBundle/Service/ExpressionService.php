<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Service;

use App\Entity\Event;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Parser\ExpressionParser;
use App\ExpressionBundle\Runner\ExpressionRunner;
use App\ExpressionBundle\Source\SourceResolver;

/**
 * Public API for the expression engine.
 * Requires an authenticated user for every evaluation.
 */
final class ExpressionService
{
    public function __construct(
        private readonly ExpressionParser $parser,
        private readonly ExpressionRunner $runner,
        private readonly SourceResolver $sourceResolver,
        private readonly RuntimeContextFactory $contextFactory,
        private readonly FeedCacheService $cache,
    ) {}

    /**
     * Evaluate a kind:30880 expression event.
     *
     * @param string $userPubkey Hex pubkey of the authenticated user (required)
     * @return NormalizedItem[]
     */
    public function evaluate(Event $expression, string $userPubkey): array
    {
        $ctx = $this->contextFactory->create($userPubkey);
        $pipeline = $this->parser->parse($expression);
        return $this->runner->run($pipeline, $ctx, $this->sourceResolver);
    }

    /**
     * Evaluate with caching.
     * @return NormalizedItem[]
     */
    public function evaluateCached(Event $expression, string $userPubkey): array
    {
        $ctx = $this->contextFactory->create($userPubkey);
        $cacheKey = $this->cache->buildKey($expression, $ctx);

        return $this->cache->getOrSet($cacheKey, fn() => $this->evaluate($expression, $userPubkey));
    }
}

