<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Service;

use App\Entity\Event;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Parser\ExpressionParser;
use App\ExpressionBundle\Runner\ExpressionRunner;
use App\ExpressionBundle\Source\SourceResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Public API for the expression engine.
 * Requires an authenticated user for every evaluation.
 */
final class ExpressionService
{
    public function __construct(
        private readonly ExpressionParser $parser,
        private readonly ExpressionRunner $runner,
        private readonly SourceResolverInterface $sourceResolver,
        private readonly RuntimeContextFactory $contextFactory,
        private readonly FeedCacheService $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Evaluate a kind:30880 expression event.
     *
     * @param string $userPubkey Hex pubkey of the authenticated user (required)
     * @return NormalizedItem[]
     */
    public function evaluate(Event $expression, string $userPubkey, bool $enforceTimeout = true): array
    {
        $start = microtime(true);
        $coordinate = $this->buildCoordinate($expression);

        $this->logger->info('Evaluating expression', [
            'coordinate' => $coordinate,
            'user' => substr($userPubkey, 0, 12) . '…',
            'enforceTimeout' => $enforceTimeout,
        ]);

        try {
            $ctx = $this->contextFactory->create($userPubkey);
            $pipeline = $this->parser->parse($expression);
            $result = $this->runner->run($pipeline, $ctx, $this->sourceResolver, $enforceTimeout);

            $this->logger->info('Expression evaluated', [
                'coordinate' => $coordinate,
                'resultItems' => count($result),
                'totalMs' => round((microtime(true) - $start) * 1000),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Expression evaluation failed', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'totalMs' => round((microtime(true) - $start) * 1000),
            ]);
            throw $e;
        }
    }

    /**
     * Evaluate with caching.
     * @return NormalizedItem[]
     */
    public function evaluateCached(Event $expression, string $userPubkey, bool $enforceTimeout = true): array
    {
        $ctx = $this->contextFactory->create($userPubkey);
        $cacheKey = $this->cache->buildKey($expression, $ctx);

        return $this->cache->getOrSet($cacheKey, fn() => $this->evaluate($expression, $userPubkey, $enforceTimeout));
    }

    /**
     * Build the cache key for an expression + user without evaluating.
     */
    public function buildCacheKey(Event $expression, string $userPubkey): string
    {
        $ctx = $this->contextFactory->create($userPubkey);
        return $this->cache->buildKey($expression, $ctx);
    }

    /**
     * Check if cached results exist for this expression + user.
     * @return NormalizedItem[]|null  Cached results or null if cache is cold
     */
    public function getCachedResults(Event $expression, string $userPubkey): ?array
    {
        $ctx = $this->contextFactory->create($userPubkey);
        $cacheKey = $this->cache->buildKey($expression, $ctx);

        return $this->cache->get($cacheKey);
    }

    private function buildCoordinate(Event $expression): string
    {
        $dTag = '';
        foreach ($expression->getTags() as $tag) {
            if (($tag[0] ?? '') === 'd' && isset($tag[1])) {
                $dTag = $tag[1];
                break;
            }
        }
        return "{$expression->getKind()}:{$expression->getPubkey()}:{$dTag}";
    }
}
