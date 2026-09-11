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
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

final class EntityManagerFactory
{
    public function __construct(
        private readonly Repository $config,
        private readonly string $basePath,
        private readonly ?QueryCollector $queries = null,
    ) {
    }

    public function create(?CacheItemPoolInterface $appCache = null): EntityManagerInterface
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
        $metaCache = null;
        if (!$isDev) {
            // Pass cache into ORMSetup — otherwise Doctrine tries Redis on 127.0.0.1 when isDev=false.
            $metaCache = new FilesystemAdapter('doctrine_meta', 0, $this->basePath . '/storage/cache/doctrine');
        }
        $setup = ORMSetup::createAttributeMetadataConfiguration($paths, $isDev, null, $metaCache);
        $setup->enableNativeLazyObjects(true);
        $setup->addCustomStringFunction('REPLACE', Dql\ReplaceFunction::class);

        // In debug, do not cache metadata to disk — otherwise the map stays stale after entity changes.
        if ($metaCache !== null) {
            $setup->setMetadataCache($metaCache);
            $setup->setQueryCache($metaCache);
        }

        if ($appCache !== null && (bool) $this->config->get('cache.doctrine_result', true)) {
            $setup->setResultCache($appCache);
        }

        // Always collect SQL timings so Vitality (and DebugBar in debug) can inspect requests.
        if ($this->queries !== null) {
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
                'wrapperClass' => CachingConnection::class,
            ],
            'pgsql' => [
                'driver' => 'pdo_pgsql',
                'host' => $this->config->get('database.host'),
                'port' => $this->config->get('database.port') ?: 5432,
                'dbname' => $this->config->get('database.name'),
                'user' => $this->config->get('database.username'),
                'password' => $this->config->get('database.password'),
                'charset' => 'utf8',
                'wrapperClass' => CachingConnection::class,
            ],
            default => [
                'driver' => 'pdo_sqlite',
                'path' => $this->sqlitePath(),
                'wrapperClass' => CachingConnection::class,
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
