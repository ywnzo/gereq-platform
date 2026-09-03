<?php

declare(strict_types=1);

namespace Gereq\Debug\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\ParameterType;
use Gereq\Debug\QueryCollector;

final class QueryStatement extends AbstractStatementMiddleware
{
    /** @var array<int|string, mixed> */
    private array $params = [];

    public function __construct(
        DriverStatement $statement,
        private readonly QueryCollector $queries,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->params[$param] = $value;
        parent::bindValue($param, $value, $type);
    }

    public function execute(): Result
    {
        $start = hrtime(true);
        $result = parent::execute();
        $this->queries->add(
            $this->sql,
            $this->params,
            (hrtime(true) - $start) / 1_000_000,
            $this->origin(),
        );

        return $result;
    }

    private function origin(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
            $file = $frame['file'] ?? '';
            if ($file !== '' && !str_contains($file, '/vendor/') && !str_contains($file, '/src/Debug/')) {
                return basename($file) . ':' . ($frame['line'] ?? 0);
            }
        }

        return '';
    }
}
