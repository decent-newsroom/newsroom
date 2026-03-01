<?php

declare(strict_types=1);

namespace App\Session;

use Psr\Log\LoggerInterface;

/**
 * Redis-backed session handler that degrades gracefully when Redis is unreachable.
 *
 * Instead of wrapping Symfony's RedisSessionHandler (which may not be available),
 * this talks to the \Redis extension directly. When Redis is down, read() returns
 * an empty string so the user appears anonymous — no 502.
 */
class ResilientRedisSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private \Redis $redis;
    private LoggerInterface $logger;
    private string $prefix;
    private int $ttl;

    public function __construct(\Redis $redis, LoggerInterface $logger, array $options = [])
    {
        $this->redis = $redis;
        $this->logger = $logger;
        $this->prefix = $options['prefix'] ?? 'sf_s';
        $this->ttl = (int) ($options['ttl'] ?? (int) \ini_get('session.gc_maxlifetime'));
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $data = $this->redis->get($this->prefix . $id);
            return $data !== false ? $data : '';
        } catch (\Throwable $e) {
            $this->logger->warning('Redis session read failed, user will appear anonymous', [
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            return $this->redis->setex($this->prefix . $id, $this->ttl, $data);
        } catch (\Throwable $e) {
            $this->logger->warning('Redis session write failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->redis->del($this->prefix . $id);
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis handles expiry via TTL — nothing to garbage-collect.
        return 0;
    }

    public function validateId(string $id): bool
    {
        try {
            return (bool) $this->redis->exists($this->prefix . $id);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            return $this->redis->expire($this->prefix . $id, $this->ttl);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

