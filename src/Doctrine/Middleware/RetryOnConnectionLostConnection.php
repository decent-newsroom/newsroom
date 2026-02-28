<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Connection wrapper that detects stale connections and reconnects transparently.
 *
 * On the first connection-lost error for any operation (query, exec, prepare),
 * it discards the dead connection, obtains a fresh one from the factory,
 * and retries the operation exactly once.
 *
 * This is safe because:
 * - The original operation failed BEFORE any data was committed
 * - We only retry once (no infinite loops)
 * - We only retry for specific connection-lost errors, not query errors
 */
class RetryOnConnectionLostConnection implements ConnectionInterface
{
    /** @var callable(): ConnectionInterface */
    private $connectionFactory;

    private ConnectionInterface $connection;

    /**
     * @param callable(): ConnectionInterface $connectionFactory
     */
    public function __construct(ConnectionInterface $connection, callable $connectionFactory)
    {
        $this->connection = $connection;
        $this->connectionFactory = $connectionFactory;
    }

    public function prepare(string $sql): Statement
    {
        try {
            return $this->connection->prepare($sql);
        } catch (\Throwable $e) {
            if (RetryOnConnectionLostDriver::isConnectionLostError($e)) {
                $this->reconnect();
                return $this->connection->prepare($sql);
            }
            throw $e;
        }
    }

    public function query(string $sql): Result
    {
        try {
            return $this->connection->query($sql);
        } catch (\Throwable $e) {
            if (RetryOnConnectionLostDriver::isConnectionLostError($e)) {
                $this->reconnect();
                return $this->connection->query($sql);
            }
            throw $e;
        }
    }

    public function quote(string $value): string
    {
        return $this->connection->quote($value);
    }

    public function exec(string $sql): int|string
    {
        try {
            return $this->connection->exec($sql);
        } catch (\Throwable $e) {
            if (RetryOnConnectionLostDriver::isConnectionLostError($e)) {
                $this->reconnect();
                return $this->connection->exec($sql);
            }
            throw $e;
        }
    }

    public function lastInsertId(): int|string
    {
        return $this->connection->lastInsertId();
    }

    public function beginTransaction(): void
    {
        try {
            $this->connection->beginTransaction();
        } catch (\Throwable $e) {
            if (RetryOnConnectionLostDriver::isConnectionLostError($e)) {
                $this->reconnect();
                $this->connection->beginTransaction();
                return;
            }
            throw $e;
        }
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    public function getServerVersion(): string
    {
        return $this->connection->getServerVersion();
    }

    /**
     * {@inheritDoc}
     */
    public function getNativeConnection()
    {
        return $this->connection->getNativeConnection();
    }

    /**
     * Drop the dead connection and replace it with a fresh one from the factory.
     */
    private function reconnect(): void
    {
        $this->connection = ($this->connectionFactory)();
    }
}
