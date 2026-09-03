<?php

declare(strict_types=1);

namespace Gereq\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Gereq\Config\Repository;
use Gereq\Debug\Doctrine\QueryMiddleware;
use Gereq\Debug\QueryCollector;

final class EntityManagerFactory
{
    public function __construct(
        private readonly Repository $config,
        private readonly string $basePath,
        private readonly ?QueryCollector $queries = null,
    ) {
    }

    public function create(): EntityManagerInterface
    {
        $paths = [$this->basePath . '/src'];
        $modulesDir = $this->basePath . '/modules';

        if (is_dir($modulesDir)) {
            foreach (scandir($modulesDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $modulesDir . '/' . $entry;
                if (is_dir($path)) {
                    $paths[] = $path;
                }
            }
        }

        $isDev = (bool) $this->config->get('app.debug', false);
        $setup = ORMSetup::createAttributeMetadataConfiguration($paths, $isDev);
        $setup->enableNativeLazyObjects(true);

        if ($isDev && $this->queries !== null) {
            $setup->setMiddlewares([new QueryMiddleware($this->queries)]);
        }

        $connection = DriverManager::getConnection($this->params(), $setup);

        return new EntityManager($connection, $setup);
    }

    /** @return array<string, mixed> */
    private function params(): array
    {
        $driver = (string) $this->config->get('database.connection', 'sqlite');

        return match ($driver) {
            'mysql' => [
                'driver' => 'pdo_mysql',
                'host' => $this->config->get('database.host'),
                'port' => $this->config->get('database.port'),
                'dbname' => $this->config->get('database.name'),
                'user' => $this->config->get('database.username'),
                'password' => $this->config->get('database.password'),
                'charset' => 'utf8mb4',
            ],
            'pgsql' => [
                'driver' => 'pdo_pgsql',
                'host' => $this->config->get('database.host'),
                'port' => $this->config->get('database.port') ?: 5432,
                'dbname' => $this->config->get('database.name'),
                'user' => $this->config->get('database.username'),
                'password' => $this->config->get('database.password'),
                'charset' => 'utf8',
            ],
            default => [
                'driver' => 'pdo_sqlite',
                'path' => $this->sqlitePath(),
            ],
        };
    }

    private function sqlitePath(): string
    {
        $path = (string) $this->config->get('database.database', 'storage/database.sqlite');
        if (!str_starts_with($path, '/')) {
            $path = $this->basePath . '/' . $path;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $path;
    }
}
