<?php

declare(strict_types=1);

namespace App\Unfold;

use DecentNewsroom\UnfoldBundle\Contract\ReaderWriteLimiterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ReaderWriteLimiterAdapter implements ReaderWriteLimiterInterface
{
    public function __construct(#[Autowire(service: 'Redis')] private \Redis $redis) {}

    public function consume(string $readerPubkey): bool
    {
        try {
            $count = $this->redis->eval(
                "local n = redis.call('INCR', KEYS[1]); if n == 1 then redis.call('EXPIRE', KEYS[1], 60) end; return n",
                ['unfold:reader-write:' . $readerPubkey],
                1,
            );
        } catch (\RedisException $e) {
            throw new \RuntimeException('Reader write limiter is unavailable.', 0, $e);
        }
        if (!is_int($count)) {
            throw new \RuntimeException('Reader write limiter returned an invalid result.');
        }
        return $count <= 60;
    }
}
