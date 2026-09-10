<?php

declare(strict_types=1);

namespace Gereq\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;

/**
 * Request-scoped cache for Doctrine schema introspection helpers.
 *
 * Connection::getDatabase() and each createSchemaManager() otherwise re-query
 * CURRENT_DATABASE() / current_schema() on every SchemaTool / tablesExist call.
 */
final class CachingConnection extends Connection
{
    private ?string $databaseName = null;

    private bool $databaseNameResolved = false;

    private ?AbstractSchemaManager $schemaManager = null;

    public function getDatabase(): ?string
    {
        if ($this->databaseNameResolved) {
            return $this->databaseName;
        }

        $params = $this->getParams();
        $dbname = $params['dbname'] ?? null;
        if (is_string($dbname) && $dbname !== '') {
            $this->databaseName = $dbname;
            $this->databaseNameResolved = true;

            return $this->databaseName;
        }

        $this->databaseName = parent::getDatabase();
        $this->databaseNameResolved = true;

        return $this->databaseName;
    }

    public function createSchemaManager(): AbstractSchemaManager
    {
        return $this->schemaManager ??= parent::createSchemaManager();
    }
}
