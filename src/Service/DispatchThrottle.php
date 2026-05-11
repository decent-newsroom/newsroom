<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Generic Redis-backed dispatch throttle using SET NX.
 *
 * Prevents the same logical "job key" from being dispatched more than once
 * within a configurable TTL window.  Pattern matches ProfileUpdateDispatcher
 * but is not tied to any specific message type.
 *
 * Usage:
 *   if ($throttle->acquire('comments_fetch', $coordinate, 60)) {
 *       $bus->dispatch(new FetchCommentsMessage($coordinate));
 *   }
 */
class DispatchThrottle
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Attempt to acquire a dispatch slot for the given namespace + key.
     *
     * Returns true when the slot was newly acquired (caller SHOULD dispatch).
     * Returns false when a slot already exists within the TTL (caller should skip).
     *
     * @param string $namespace  Logical grouping, e.g. 'comments_fetch'
     * @param string $key        Unique identity within the namespace, e.g. coordinate or pubkey
     * @param int    $ttlSeconds How long to suppress duplicate dispatches
     */
    public function acquire(string $namespace, string $key, int $ttlSeconds): bool
    {
        try {
            $redisKey = 'dispatch_throttle:' . $namespace . ':' . $key;
            $result = $this->redis->set($redisKey, '1', ['NX', 'EX' => $ttlSeconds]);
            return $result !== false;
        } catch (\RedisException $e) {
            // Redis unavailable — allow dispatch so jobs still run
            $this->logger->warning('Redis unavailable in DispatchThrottle, bypassing', [
                'namespace' => $namespace,
                'key'       => substr($key, 0, 16),
                'error'     => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * Explicitly release a throttle slot (e.g. on dispatch failure so a retry can proceed).
     */
    public function release(string $namespace, string $key): void
    {
        try {
            $this->redis->del('dispatch_throttle:' . $namespace . ':' . $key);
        } catch (\RedisException) {
            // best-effort
        }
    }
}

