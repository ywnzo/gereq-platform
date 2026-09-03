<?php

declare(strict_types=1);

namespace Gereq\Debug\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Gereq\Debug\QueryCollector;
use SensitiveParameter;

final class QueryDriver extends AbstractDriverMiddleware
{
    public function __construct(DriverInterface $driver, private readonly QueryCollector $queries)
    {
        parent::__construct($driver);
    }

    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        return new QueryConnection(parent::connect($params), $this->queries);
    }
}
