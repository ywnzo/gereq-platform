<?php

declare(strict_types=1);

namespace Gereq\Debug\Doctrine;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Gereq\Debug\QueryCollector;

final class QueryConnection extends AbstractConnectionMiddleware
{
    public function __construct(DriverConnection $connection, private readonly QueryCollector $queries)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): DriverStatement
    {
        return new QueryStatement(parent::prepare($sql), $this->queries, $sql);
    }

    public function query(string $sql): Result
    {
        $start = hrtime(true);
        $result = parent::query($sql);
        $this->queries->add($sql, [], (hrtime(true) - $start) / 1_000_000, $this->origin());

        return $result;
    }

    public function exec(string $sql): int|string
    {
        $start = hrtime(true);
        $result = parent::exec($sql);
        $this->queries->add($sql, [], (hrtime(true) - $start) / 1_000_000, $this->origin());

        return $result;
    }

    private function origin(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $file = $frame['file'] ?? '';
            if ($file !== '' && !str_contains($file, '/vendor/') && !str_contains($file, '/src/Debug/')) {
                return basename($file) . ':' . ($frame['line'] ?? 0);
            }
        }

        return '';
    }
}
