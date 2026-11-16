<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace\Metrics;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/** Counts attempted executions without retaining SQL or bound values. */
final readonly class WorkspaceQueryMiddleware implements Middleware
{
    public function __construct(private WorkspaceMetrics $metrics) {}

    public function wrap(Driver $driver): Driver
    {
        if (!$this->metrics->isEnabled()) {
            return $driver;
        }

        return new class($driver, $this->metrics) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly WorkspaceMetrics $metrics)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->metrics) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly WorkspaceMetrics $metrics)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        return new class(parent::prepare($sql), $this->metrics) extends AbstractStatementMiddleware {
                            public function __construct(Statement $statement, private readonly WorkspaceMetrics $metrics)
                            {
                                parent::__construct($statement);
                            }

                            public function execute(): Result
                            {
                                $this->metrics->query();
                                return parent::execute();
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $this->metrics->query();
                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->metrics->query();
                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
