<?php

namespace App\Session;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\AbstractSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

/**
 * A session handler that tries Redis first, but gracefully falls back to files
 * if Redis is unavailable at runtime.
 */
class GracefulSessionHandler extends AbstractSessionHandler
{
    private RedisSessionHandler $redisHandler;
    private NativeFileSessionHandler $fileHandler;
    private LoggerInterface $logger;

    private bool $useRedis = true;
    private bool $handlerOpened = false;

    public function __construct(RedisSessionHandler $redisHandler, NativeFileSessionHandler $fileHandler, LoggerInterface $logger)
    {
        $this->redisHandler = $redisHandler;
        $this->fileHandler = $fileHandler;
        $this->logger = $logger;
    }

    protected function doRead(string $sessionId): string
    {
        if ($this->useRedis) {
            try {
                $this->handlerOpened = true;
                return $this->redisHandler->read($sessionId);
            } catch (\Throwable $e) {
                $this->useRedis = false;
                $this->logger->warning('Redis session read failed; falling back to files', ['e' => $e->getMessage()]);
            }
        }
        $this->handlerOpened = true;
        return $this->fileHandler->read($sessionId);
    }

    public function updateTimestamp(string $sessionId, string $data): bool
    {
        if ($this->useRedis) {
            try {
                return $this->redisHandler->updateTimestamp($sessionId, $data);
            } catch (\Throwable $e) {
                $this->useRedis = false;
                $this->logger->warning('Redis session touch failed; falling back to files', ['e' => $e->getMessage()]);
            }
        }
        // NativeFileSessionHandler doesn't support updateTimestamp, just return true
        return true;
    }

    protected function doWrite(string $sessionId, string $data): bool
    {
        if ($this->useRedis) {
            try {
                return $this->redisHandler->write($sessionId, $data);
            } catch (\Throwable $e) {
                $this->useRedis = false;
                $this->logger->warning('Redis session write failed; falling back to files', ['e' => $e->getMessage()]);
            }
        }
        return $this->fileHandler->write($sessionId, $data);
    }

    protected function doDestroy(string $sessionId): bool
    {
        if ($this->useRedis) {
            try {
                return $this->redisHandler->destroy($sessionId);
            } catch (\Throwable $e) {
                $this->useRedis = false;
                $this->logger->warning('Redis session destroy failed; falling back to files', ['e' => $e->getMessage()]);
            }
        }
        return $this->fileHandler->destroy($sessionId);
    }

    public function close(): bool
    {
        if (!$this->handlerOpened) {
            return true;
        }

        try {
            if ($this->useRedis) {
                return $this->redisHandler->close();
            } else {
                return $this->fileHandler->close();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Session close error', ['e' => $e->getMessage()]);
            return false;
        } finally {
            $this->handlerOpened = false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        if ($this->useRedis) {
            try {
                return $this->redisHandler->gc($max_lifetime);
            } catch (\Throwable $e) {
                $this->useRedis = false;
                $this->logger->warning('Redis session gc failed; falling back to files', ['e' => $e->getMessage()]);
            }
        }
        return $this->fileHandler->gc($max_lifetime);
    }
}


