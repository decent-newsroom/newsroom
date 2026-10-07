<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

class AuthorContentCacheInvalidator
{
    public function __construct(
        private readonly \Redis $redis,
        #[Autowire(service: 'articles.cache')]
        private readonly CacheItemPoolInterface $articles,
        #[Autowire(service: 'npub.cache')]
        private readonly CacheItemPoolInterface $profiles,
        #[Autowire(service: 'unfold.cache')]
        private readonly CacheItemPoolInterface $publications,
        private readonly CacheInterface $cache,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
    ) {
    }

    public function invalidate(): void
    {
        // Other authors' lists/publications can embed the deleted content too.
        foreach ([$this->articles, $this->profiles, $this->publications] as $pool) {
            if (!$pool->clear()) {
                throw new \RuntimeException('Could not invalidate content cache pool.');
            }
        }
        if (!$this->cache->delete('media_discovery_events_all_' . $this->environment)) {
            throw new \RuntimeException('Could not invalidate media discovery cache.');
        }
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, 'view:*', 500);
            if ($keys !== false && $keys !== []) {
                if ($this->redis->del($keys) === false) {
                    throw new \RuntimeException('Could not invalidate Redis content views.');
                }
            }
        } while ($cursor !== 0);
    }
}
