<?php

declare(strict_types=1);

namespace Gereq\Debug\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Gereq\Debug\QueryCollector;

final class QueryMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly QueryCollector $queries)
    {
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new QueryDriver($driver, $this->queries);
    }
}
