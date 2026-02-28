<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Driver wrapper that reconnects on stale/lost database connections.
 *
 * When a connection-lost error is detected, the driver drops the old connection
 * and creates a fresh one. This prevents long-lived FrankenPHP workers from
 * serving 502s after a database idle timeout or transient network issue.
 */
class RetryOnConnectionLostDriver extends AbstractDriverMiddleware
{
    /**
     * PostgreSQL error codes/messages that indicate a lost connection.
     * These are safe to retry because no partial transaction is in progress.
     */
    private const CONNECTION_LOST_PATTERNS = [
        'server closed the connection unexpectedly',
        'no connection to the server',
        'connection is closed',
        'connection timed out',
        'connection reset by peer',
        'broken pipe',
        'SSL connection has been closed unexpectedly',
        'terminating connection due to administrator command',
        'could not connect to server',
        'connection refused',
        'SQLSTATE[08',   // Connection exception class (08006, 08001, 08003, etc.)
        'SQLSTATE[HY000] [2002]', // Connection refused
        'SQLSTATE[57P01]',        // admin_shutdown
        'SQLSTATE[57P03]',        // cannot_connect_now
    ];

    public function connect(array $params): ConnectionInterface
    {
        return new RetryOnConnectionLostConnection(
            parent::connect($params),
            fn () => parent::connect($params),
        );
    }

    public static function isConnectionLostError(\Throwable $e): bool
    {
        $message = $e->getMessage();

        foreach (self::CONNECTION_LOST_PATTERNS as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        // Also check the previous exception chain
        if ($e->getPrevious() !== null) {
            return self::isConnectionLostError($e->getPrevious());
        }

        return false;
    }
}

