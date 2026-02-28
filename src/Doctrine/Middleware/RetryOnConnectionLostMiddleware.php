<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;

/**
 * DBAL Middleware that wraps the driver to retry on lost connections.
 *
 * In FrankenPHP worker mode, PHP workers are long-lived and database connections
 * can go stale (PostgreSQL idle timeout, network blip, DB restart). Without this,
 * a stale connection causes an unrecoverable exception that crashes the worker → 502.
 *
 * This middleware catches "connection lost" errors and retries once with a fresh connection.
 */
class RetryOnConnectionLostMiddleware implements MiddlewareInterface
{
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new RetryOnConnectionLostDriver($driver);
    }
}

