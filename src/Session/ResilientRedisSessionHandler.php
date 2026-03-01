<?php

declare(strict_types=1);

namespace App\Session;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

/**
 * Wraps RedisSessionHandler to prevent Redis failures from producing 502 errors.
 *
 * When Redis is unreachable the security firewall cannot read the session token,
 * so Symfony treats the visitor as anonymous. This is an acceptable degradation
 * compared to a hard 502 that blocks the entire page.
 */
class ResilientRedisSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private RedisSessionHandler $inner;
    private LoggerInterface $logger;

    public function __construct(\Redis $redis, LoggerInterface $logger, array $options = [])
    {
        $this->inner = new RedisSessionHandler($redis, $options);
        $this->logger = $logger;
    }

    public function open(string $path, string $name): bool
    {
        try {
            return $this->inner->open($path, $name);
        } catch (\Throwable $e) {
            $this->logger->warning('Redis session open failed, sessions degraded', ['error' => $e->getMessage()]);
            return true;
        }
    }

    public function close(): bool
    {
        try {
            return $this->inner->close();
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function read(string $id): string|false
    {
        try {
            return $this->inner->read($id);
        } catch (\Throwable $e) {
            $this->logger->warning('Redis session read failed, user will appear anonymous', ['error' => $e->getMessage()]);
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            return $this->inner->write($id, $data);
        } catch (\Throwable $e) {
            $this->logger->warning('Redis session write failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            return $this->inner->destroy($id);
        } catch (\Throwable $e) {
            return true;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            return $this->inner->gc($max_lifetime);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function validateId(string $id): bool
    {
        try {
            return $this->inner->validateId($id);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            return $this->inner->updateTimestamp($id, $data);
        } catch (\Throwable $e) {
            return false;
        }
    }
}

