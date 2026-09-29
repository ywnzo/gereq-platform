<?php

declare(strict_types=1);

namespace Gereq\Database;

use Doctrine\DBAL\DriverManager;
use Gereq\Config\Repository;

/**
 * Creates the configured MySQL/PostgreSQL database when it is missing.
 * SQLite paths are created by {@see EntityManagerFactory} as needed.
 *
 * Host CLI without the PDO driver (e.g. `php gereq serve-docker`) skips quietly —
 * the PHP container performs auto-create on first request.
 */
final class DatabaseCreator
{
    public function __construct(
        private readonly Repository $config,
    ) {
    }

    /**
     * @return bool true when a new database was created on the server
     */
    public function ensureExists(): bool
    {
        $driver = (string) $this->config->get('database.connection', 'sqlite');

        return match ($driver) {
            'pgsql' => $this->ensurePostgres(),
            'mysql' => $this->ensureMysql(),
            default => false,
        };
    }

    private function ensurePostgres(): bool
    {
        if (!extension_loaded('pdo_pgsql')) {
            return false;
        }

        $name = trim((string) $this->config->get('database.name', 'gereq'));
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid DB_DATABASE_NAME for auto-create.');
        }

        $params = [
            'driver' => 'pdo_pgsql',
            'host' => $this->config->get('database.host'),
            'port' => $this->config->get('database.port') ?: 5432,
            'dbname' => 'postgres',
            'user' => $this->config->get('database.username'),
            'password' => $this->config->get('database.password'),
        ];

        try {
            $conn = DriverManager::getConnection($params);
            try {
                $exists = $conn->fetchOne('SELECT 1 FROM pg_database WHERE datname = ?', [$name]);
                if ($exists) {
                    return false;
                }
                // CREATE DATABASE cannot run in a transaction.
                $conn->executeStatement('CREATE DATABASE ' . $name . " ENCODING 'UTF8'");
                $this->log('Created PostgreSQL database "' . $name . '".');

                return true;
            } finally {
                $conn->close();
            }
        } catch (\Throwable $e) {
            if (self::isUnreachable($e)) {
                return false;
            }
            throw $e;
        }
    }

    private function ensureMysql(): bool
    {
        if (!extension_loaded('pdo_mysql')) {
            return false;
        }

        $name = trim((string) $this->config->get('database.name', 'gereq'));
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid DB_DATABASE_NAME for auto-create.');
        }

        $params = [
            'driver' => 'pdo_mysql',
            'host' => $this->config->get('database.host'),
            'port' => $this->config->get('database.port') ?: 3306,
            'user' => $this->config->get('database.username'),
            'password' => $this->config->get('database.password'),
            'charset' => 'utf8mb4',
        ];

        try {
            $conn = DriverManager::getConnection($params);
            try {
                $exists = $conn->fetchOne(
                    'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
                    [$name],
                );
                if ($exists) {
                    return false;
                }
                $conn->executeStatement(
                    'CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                );
                $this->log('Created MySQL database "' . $name . '".');

                return true;
            } finally {
                $conn->close();
            }
        } catch (\Throwable $e) {
            if (self::isUnreachable($e)) {
                return false;
            }
            throw $e;
        }
    }

    public static function isUnreachable(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        foreach ([
            'could not find driver',
            'connection refused',
            'could not translate host name',
            'php_network_getaddresses',
            'no such host',
            'nodename nor servname',
            'name or service not known',
            'timed out',
            'connection timed out',
            'operation timed out',
            'network is unreachable',
            'no route to host',
        ] as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }

        $prev = $e->getPrevious();

        return $prev instanceof \Throwable && self::isUnreachable($prev);
    }

    private function log(string $message): void
    {
        $line = '[gereq] ' . $message;
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            if (\defined('STDERR') && is_resource(STDERR)) {
                fwrite(STDERR, $line . "\n");
            } else {
                echo $line . "\n";
            }

            return;
        }
        error_log($line);
    }
}
