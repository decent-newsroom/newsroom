<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Service;

use App\Entity\Event;
use App\ExpressionBundle\Model\RuntimeContext;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Redis caching layer for evaluated feed results (per-user).
 */
final class FeedCacheService
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly int $expressionCacheTtl = 300,
    ) {}

    public function buildKey(Event $expression, RuntimeContext $ctx): string
    {
        $dTag = null;
        foreach ($expression->getTags() as $tag) {
            if (($tag[0] ?? '') === 'd' && isset($tag[1])) {
                $dTag = $tag[1];
                break;
            }
        }

        $coordinate = "{$expression->getKind()}:{$expression->getPubkey()}:{$dTag}";
        $ctxHash = md5(
            implode(',', $ctx->contacts) . '|' . implode(',', $ctx->interests)
        );

        return 'feed:' . md5("{$coordinate}:{$ctx->mePubkey}:{$ctxHash}");
    }

    /**
     * @param callable $factory Returns NormalizedItem[]
     * @return array NormalizedItem[]
     */
    public function getOrSet(string $cacheKey, callable $factory): array
    {
        try {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                $cached = $item->get();
                if (is_array($cached)) {
                    return $cached;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Feed cache read failed', ['error' => $e->getMessage()]);
        }

        $result = $factory();

        try {
            $item = $this->cache->getItem($cacheKey);
            $item->set($result);
            $item->expiresAfter($this->expressionCacheTtl);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->warning('Feed cache write failed', ['error' => $e->getMessage()]);
        }

        return $result;
    }
}

