<?php

declare(strict_types=1);

namespace App\Session;

use Psr\Log\LoggerInterface;

/**
 * Redis-backed session handler that degrades gracefully when Redis is unreachable.
 *
 * Talks to the \Redis extension directly. When Redis is down, read() returns
 * an empty string so the user appears anonymous — no 502.
 *
 * The injected \Redis service is typically a Symfony lazy proxy. The very first
 * method call triggers connect() + auth(), which can fail with \RedisException,
 * \Error, or other throwables depending on the proxy implementation.  Every
 * public method therefore catches \Throwable to guarantee the handler never
 * crashes the request.
 */
class ResilientRedisSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private \Redis $redis;
    private LoggerInterface $logger;
    private string $prefix;
    private int $ttl;
    private bool $redisAvailable = true;

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
        if (!$this->redisAvailable) {
            return '';
        }

        try {
            $data = $this->redis->get($this->prefix . $id);
            return $data !== false ? $data : '';
        } catch (\Throwable $e) {
            $this->markUnavailable($e, 'read');
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        if (!$this->redisAvailable) {
            return false;
        }

        try {
            return (bool) $this->redis->setex($this->prefix . $id, $this->ttl, $data);
        } catch (\Throwable $e) {
            $this->markUnavailable($e, 'write');
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        if (!$this->redisAvailable) {
            return true;
        }

        try {
            $this->redis->del($this->prefix . $id);
            return true;
        } catch (\Throwable $e) {
            $this->markUnavailable($e, 'destroy');
            return true;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    public function validateId(string $id): bool
    {
        if (!$this->redisAvailable) {
            return false;
        }

        try {
            return (bool) $this->redis->exists($this->prefix . $id);
        } catch (\Throwable $e) {
            $this->markUnavailable($e, 'validateId');
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        if (!$this->redisAvailable) {
            return false;
        }

        try {
            return (bool) $this->redis->expire($this->prefix . $id, $this->ttl);
        } catch (\Throwable $e) {
            $this->markUnavailable($e, 'updateTimestamp');
            return false;
        }
    }

    private function markUnavailable(\Throwable $e, string $operation): void
    {
        $this->redisAvailable = false;
        $this->logger->warning('Redis session ' . $operation . ' failed — sessions degraded for this request', [
            'error' => $e->getMessage(),
            'class' => get_class($e),
        ]);
    }
}

