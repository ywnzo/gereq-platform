<?php

declare(strict_types=1);

namespace Gereq\Database;

use Gereq\Config\Repository;

/**
 * Shared Doctrine DBAL connection parameters (timeouts, driver-specific keys).
 */
final class DbalConnectionParams
{
    /**
     * @return array<string, mixed>
     */
    public static function forConfig(Repository $config, string $basePath, ?string $databaseName = null): array
    {
        $driver = (string) $config->get('database.connection', 'sqlite');

        return match ($driver) {
            'mysql' => self::mysql($config, $databaseName),
            'pgsql' => self::pgsql($config, $databaseName),
            default => self::sqlite($config, $basePath),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function mysql(Repository $config, ?string $databaseName): array
    {
        $params = [
            'driver' => 'pdo_mysql',
            'host' => $config->get('database.host'),
            'port' => $config->get('database.port'),
            'dbname' => $databaseName ?? $config->get('database.name'),
            'user' => $config->get('database.username'),
            'password' => $config->get('database.password'),
            'charset' => 'utf8mb4',
        ];

        return self::withConnectTimeout($params);
    }

    /**
     * @return array<string, mixed>
     */
    private static function pgsql(Repository $config, ?string $databaseName): array
    {
        $params = [
            'driver' => 'pdo_pgsql',
            'host' => $config->get('database.host'),
            'port' => $config->get('database.port') ?: 5432,
            'dbname' => $databaseName ?? $config->get('database.name'),
            'user' => $config->get('database.username'),
            'password' => $config->get('database.password'),
            'charset' => 'utf8',
        ];

        return self::withConnectTimeout($params);
    }

    /**
     * @return array<string, mixed>
     */
    private static function sqlite(Repository $config, string $basePath): array
    {
        $path = (string) $config->get('database.database', 'storage/database.sqlite');
        if (!str_starts_with($path, '/')) {
            $path = rtrim($basePath, '/') . '/' . $path;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return [
            'driver' => 'pdo_sqlite',
            'path' => $path,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function withConnectTimeout(array $params): array
    {
        $raw = env_value('DB_CONNECT_TIMEOUT', 3);
        $timeout = is_numeric($raw) ? (int) $raw : 3;
        $timeout = max(1, min(30, $timeout));
        $params['connect_timeout'] = $timeout;

        return $params;
    }
}
