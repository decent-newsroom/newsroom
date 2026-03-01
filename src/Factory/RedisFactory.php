<?php

declare(strict_types=1);

namespace App\Factory;

use Psr\Log\LoggerInterface;

/**
 * Factory that creates a \Redis connection.
 *
 * Replaces the lazy-proxy approach from services.yaml. The lazy proxy's
 * deferred connect() + auth() can crash the FrankenPHP worker with a fatal
 * error when Redis is unreachable, because the proxy initializer does not
 * wrap exceptions in a way PHP's session machinery can recover from.
 *
 * This factory eagerly connects. If the connection fails, it still returns
 * a \Redis instance — callers must handle \RedisException on every call.
 */
class RedisFactory
{
    public static function create(
        string $host,
        int $port,
        string $password,
        LoggerInterface $logger,
    ): \Redis {
        $redis = new \Redis();

        try {
            // Use a short connect timeout so a down Redis doesn't block the worker.
            $redis->connect($host, $port, 2.0);
            $redis->auth($password);
        } catch (\Throwable $e) {
            $logger->warning('Redis connection failed in factory — services using Redis will degrade', [
                'host' => $host,
                'port' => $port,
                'error' => $e->getMessage(),
            ]);
            // Return the unconnected \Redis instance.
            // Every subsequent call on it will throw \RedisException,
            // which callers (ResilientRedisSessionHandler, RedisCacheService, etc.)
            // must catch.
        }

        return $redis;
    }
}

