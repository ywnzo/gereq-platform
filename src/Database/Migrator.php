<?php

declare(strict_types=1);

namespace Gereq\Database;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Module\ModuleManager;

final class Migrator
{
    /** @var array<string, string>|null version => ran_at */
    private ?array $ranCache = null;

    private bool $migrationsTableReady = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ModuleManager $modules,
    ) {
    }

    /**
     * Drop all mapped tables and recreate schema from metadata + module migrations.
     *
     * @return list<string>
     */
    public function fresh(): array
    {
        $messages = [];
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        if ($metadata !== []) {
            $tool->dropSchema($metadata);
            $messages[] = 'Schéma smazáno (' . count($metadata) . ' entit).';
        }

        $conn = $this->em->getConnection();
        try {
            $conn->fetchOne('SELECT 1 FROM schema_migrations LIMIT 1 /* gereq:warm */');
            $conn->executeStatement('DELETE FROM schema_migrations');
            $this->ranCache = [];
            $this->migrationsTableReady = true;
            $messages[] = 'Tabulka schema_migrations vyčištěna.';
        } catch (\Throwable) {
            // Table does not exist — migrate() will create it.
            $this->ranCache = [];
            $this->migrationsTableReady = false;
        }

        // Application boot may have cached enabled modules from the pre-drop DB.
        $this->modules->rediscover();
        $this->em->clear();

        return array_merge($messages, $this->migrate());
    }

    /**
     * Run pending file migrations for enabled modules, then sync Doctrine schema
     * for core + enabled modules. Disabled modules are never migrated here —
     * their migrations run on enable ({@see migrateModule()}).
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        $messages = [];
        $this->ensureMigrationsTable();

        // File migrations first (they have DEFAULT / backfill), then SchemaTool.
        foreach ($this->modules->discover() as $manifest) {
            if (!$this->modules->isEnabled($manifest->id)) {
                continue;
            }
            $messages = array_merge($messages, $this->migrateModule($manifest->id, syncSchema: false));
        }

        return array_merge($messages, $this->syncSchema());
    }

    /**
     * Sync Doctrine entity metadata to the database (core + enabled modules).
     *
     * @return list<string>
     */
    public function syncSchema(): array
    {
        $messages = [];
        $this->refreshMetadataCache();
        $metadata = $this->schemaMetadata();
        if ($metadata === []) {
            return $messages;
        }

        $conn = $this->em->getConnection();
        $config = $conn->getConfiguration();
        $previousFilter = $config->getSchemaAssetsFilter();

        $managed = [];
        foreach ($metadata as $classMetadata) {
            $managed[strtolower($classMetadata->getTableName())] = true;
            foreach ($classMetadata->getAssociationMappings() as $mapping) {
                if (isset($mapping['joinTable']['name'])) {
                    $managed[strtolower((string) $mapping['joinTable']['name'])] = true;
                }
            }
        }

        // Compare entity tables only — otherwise SchemaTool DROPs e.g. schema_migrations.
        $config->setSchemaAssetsFilter(static function (string|\Doctrine\DBAL\Schema\AbstractAsset $asset) use ($managed): bool {
            $name = is_string($asset) ? $asset : $asset->getName();

            return isset($managed[strtolower($name)]);
        });

        try {
            $tool = new SchemaTool($this->em);
            $sql = array_values(array_filter(
                $tool->getUpdateSchemaSQL($metadata),
                static function (string $statement) use ($managed): bool {
                    // Postgres FTS column + GIN index are maintained outside Doctrine indexes
                    // (tsvector / gin). Stale metadata used to DROP the column while leaving
                    // the BEFORE INSERT trigger — breaking event submit.
                    if (preg_match('/\bsearch_vector\b/i', $statement)
                        && preg_match('/\bDROP\b/i', $statement)
                    ) {
                        return false;
                    }
                    if (preg_match('/^\s*DROP\s+INDEX\b/i', $statement)
                        && stripos($statement, 'idx_kdeaco_events_search_vector') !== false
                    ) {
                        return false;
                    }

                    // Allow DROP only for entity tables / SQLite __temp__ rebuild.
                    // Otherwise SchemaTool may drop e.g. schema_migrations.
                    if (!preg_match('/^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?["`\[]?(\w+)/i', $statement, $m)) {
                        return true;
                    }
                    $name = strtolower($m[1]);

                    return str_starts_with($name, '__temp__') || isset($managed[$name]);
                },
            ));

            foreach ($sql as $statement) {
                $conn->executeStatement($statement);
            }

            if ($sql !== []) {
                $messages[] = 'Core schema aktualizováno (' . count($sql) . ' příkazů).';
            }
        } finally {
            $config->setSchemaAssetsFilter($previousFilter);
        }

        return $messages;
    }

    /**
     * Run migration downs + drop entity tables for a module; remove schema_migrations rows.
     *
     * @return list<string>
     */
    public function rollbackModule(string $moduleId): array
    {
        $this->ensureMigrationsTable();
        $messages = [];

        $manifest = $this->modules->manifest($moduleId);
        if ($manifest === null) {
            return $messages;
        }

        $dir = $manifest->path . '/Database/Migrations';
        $conn = $this->em->getConnection();
        $schemaManager = $conn->createSchemaManager();

        if (is_dir($dir)) {
            $files = glob($dir . '/*.php') ?: [];
            rsort($files);
            $workingSchema = null;

            foreach ($files as $file) {
                $version = $manifest->id . ':' . basename($file, '.php');
                if (!$this->ran($version)) {
                    continue;
                }

                $migration = require $file;
                if (is_object($migration) && method_exists($migration, 'down')) {
                    [$fromSchema, $toSchema] = $this->schemaPair($schemaManager, $workingSchema);
                    $migration->down($toSchema);

                    $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
                    if (!$diff->isEmpty()) {
                        $schemaManager->alterSchema($diff);
                    }
                    $workingSchema = $toSchema;
                }

                $conn->executeStatement('DELETE FROM schema_migrations WHERE version = ?', [$version]);
                $this->warmRan();
                unset($this->ranCache[$version]);
                $messages[] = 'Rollback ' . $version;
            }
        }

        $meta = $this->metadataForModule($moduleId);
        if ($meta !== []) {
            $tool = new SchemaTool($this->em);
            $tool->dropSchema($meta);
            $messages[] = 'Dropped schema (' . count($meta) . ' entities) for ' . $moduleId;
        }

        return $messages;
    }

    /**
     * Rollback ran migrations whose files exist in $currentDir but not in $targetDir (downgrade prep).
     * Requires each migration to implement down().
     *
     * @return list<string>
     */
    public function rollbackMigrationsMissingFrom(string $moduleId, string $currentDir, string $targetDir): array
    {
        $this->ensureMigrationsTable();
        $messages = [];

        $currentMigrations = $this->migrationBasenames($currentDir . '/Database/Migrations');
        $targetMigrations = array_fill_keys(
            $this->migrationBasenames($targetDir . '/Database/Migrations'),
            true,
        );

        $toRollback = [];
        foreach ($currentMigrations as $basename) {
            if (isset($targetMigrations[$basename])) {
                continue;
            }
            $version = $moduleId . ':' . $basename;
            if ($this->ran($version)) {
                $toRollback[] = $basename;
            }
        }

        rsort($toRollback);
        $conn = $this->em->getConnection();
        $schemaManager = $conn->createSchemaManager();
        $workingSchema = null;

        foreach ($toRollback as $basename) {
            $file = $currentDir . '/Database/Migrations/' . $basename . '.php';
            $version = $moduleId . ':' . $basename;
            if (!is_file($file)) {
                throw new \RuntimeException('Chybí migrační soubor pro rollback: ' . $basename);
            }

            $migration = require $file;
            if (!is_object($migration) || !method_exists($migration, 'down')) {
                throw new \RuntimeException(
                    'Downgrade vyžaduje metodu down() v migraci ' . $basename . '.',
                );
            }

            [$fromSchema, $toSchema] = $this->schemaPair($schemaManager, $workingSchema);
            $migration->down($toSchema);

            $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
            if (!$diff->isEmpty()) {
                $schemaManager->alterSchema($diff);
            }
            $workingSchema = $toSchema;

            $conn->executeStatement('DELETE FROM schema_migrations WHERE version = ?', [$version]);
            $this->warmRan();
            unset($this->ranCache[$version]);
            $messages[] = 'Rollback ' . $version;
        }

        return $messages;
    }

    /** @return list<string> */
    private function migrationBasenames(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $out[] = basename($file, '.php');
        }
        sort($out);

        return $out;
    }

    /**
     * @return list<\Doctrine\ORM\Mapping\ClassMetadata<object>>
     */
    public function metadataForModule(string $moduleId): array
    {
        $manifest = $this->modules->manifest($moduleId);
        if ($manifest === null) {
            return [];
        }

        $root = realpath($manifest->path);
        if ($root === false) {
            return [];
        }

        $out = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            try {
                $file = (new \ReflectionClass($meta->getName()))->getFileName();
            } catch (\Throwable) {
                continue;
            }
            if (!is_string($file) || $file === '') {
                continue;
            }
            $real = realpath($file);
            if ($real === false) {
                continue;
            }
            if (str_starts_with($real, $root . DIRECTORY_SEPARATOR) || $real === $root) {
                $out[] = $meta;
            }
        }

        return $out;
    }

    /**
     * Metadata managed by SchemaTool: core (`src/`) + enabled modules only.
     * Disabled / not-yet-enabled module entities stay out of syncSchema so a
     * fresh migrate does not create tables for packages the client never turns on.
     *
     * @return list<\Doctrine\ORM\Mapping\ClassMetadata<object>>
     */
    private function schemaMetadata(): array
    {
        $all = $this->em->getMetadataFactory()->getAllMetadata();
        $enabled = array_fill_keys($this->modules->enabledIds(), true);
        $allowedRoots = [];
        foreach ($this->modules->discover() as $manifest) {
            if (!isset($enabled[$manifest->id])) {
                continue;
            }
            $real = realpath($manifest->path);
            if ($real !== false) {
                $allowedRoots[] = $real;
            }
        }

        // Core entities live under src/
        $coreHint = DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;

        $out = [];
        foreach ($all as $meta) {
            try {
                $file = (new \ReflectionClass($meta->getName()))->getFileName();
            } catch (\Throwable) {
                continue;
            }
            if (!is_string($file) || $file === '') {
                continue;
            }
            $real = realpath($file);
            if ($real === false) {
                continue;
            }

            if (str_contains($real, $coreHint)) {
                $out[] = $meta;
                continue;
            }

            foreach ($allowedRoots as $root) {
                if (str_starts_with($real, $root . DIRECTORY_SEPARATOR) || $real === $root) {
                    $out[] = $meta;
                    continue 2;
                }
            }
        }

        return $out;
    }

    /**
     * Run all pending migrations for a single module (files not yet in schema_migrations).
     *
     * @return list<string>
     */
    public function migrateModule(string $moduleId, bool $syncSchema = true): array
    {
        $this->ensureMigrationsTable();
        $messages = [];

        $manifest = $this->modules->manifest($moduleId);
        if ($manifest === null) {
            return $syncSchema ? $this->syncSchema() : [];
        }

        $dir = $manifest->path . '/Database/Migrations';
        if (is_dir($dir)) {
            $files = glob($dir . '/*.php') ?: [];
            sort($files);
            $conn = $this->em->getConnection();
            $schemaManager = $conn->createSchemaManager();
            $workingSchema = null;

            foreach ($files as $file) {
                $version = $manifest->id . ':' . basename($file, '.php');
                if ($this->ran($version)) {
                    continue;
                }

                $migration = require $file;
                if (!is_object($migration) || !method_exists($migration, 'up')) {
                    continue;
                }

                [$fromSchema, $toSchema] = $this->schemaPair($schemaManager, $workingSchema);
                $migration->up($toSchema);

                $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
                if (!$diff->isEmpty()) {
                    $schemaManager->alterSchema($diff);
                }

                if (method_exists($migration, 'afterUp')) {
                    $migration->afterUp($conn);
                    // afterUp may alter schema via raw SQL — next migrations must re-introspect.
                    $workingSchema = null;
                } else {
                    $workingSchema = $toSchema;
                }

                $ranAt = (new \DateTimeImmutable())->format('c');
                $conn->executeStatement(
                    'INSERT INTO schema_migrations (version, ran_at) VALUES (?, ?)',
                    [$version, $ranAt]
                );
                $this->warmRan();
                $this->ranCache[$version] = $ranAt;
                $messages[] = 'Migrace ' . $version;
            }
        }

        if ($syncSchema) {
            $messages = array_merge($messages, $this->syncSchema());
        }

        return $messages;
    }

    /**
     * @return list<array{version: string, ran: bool, ran_at: ?string, file: string}>
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();
        $items = [];

        foreach ($this->modules->discover() as $manifest) {
            foreach ($this->statusForModule($manifest->id) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @return list<array{version: string, ran: bool, ran_at: ?string, file: string}>
     */
    public function statusForModule(string $moduleId): array
    {
        $manifest = $this->modules->manifest($moduleId);
        if ($manifest === null) {
            return [];
        }

        $dir = $manifest->path . '/Database/Migrations';
        if (!is_dir($dir)) {
            return [];
        }

        $this->warmRan();
        $files = glob($dir . '/*.php') ?: [];
        sort($files);
        $items = [];

        foreach ($files as $file) {
            $basename = basename($file, '.php');
            $version = $manifest->id . ':' . $basename;
            $ranAt = $this->ranCache[$version] ?? null;
            $items[] = [
                'version' => $version,
                'ran' => $ranAt !== null,
                'ran_at' => $ranAt,
                'file' => $basename . '.php',
            ];
        }

        return $items;
    }

    /**
     * One introspection (+ clone) instead of two full introspectSchema() calls per migration.
     * When chaining migrations, reuses the current in-memory schema.
     *
     * @param AbstractSchemaManager<\Doctrine\DBAL\Connection> $schemaManager
     * @return array{0: Schema, 1: Schema}
     */
    private function schemaPair(AbstractSchemaManager $schemaManager, ?Schema $current = null): array
    {
        $fromSchema = $current ?? $schemaManager->introspectSchema();

        return [$fromSchema, clone $fromSchema];
    }

    private function ensureMigrationsTable(): void
    {
        if ($this->migrationsTableReady) {
            return;
        }

        $conn = $this->em->getConnection();
        try {
            // Cheaper than tablesExist() → listTableNames() → CURRENT_DATABASE()+current_schema().
            $conn->fetchOne('SELECT 1 FROM schema_migrations LIMIT 1 /* gereq:warm */');
            $this->migrationsTableReady = true;

            return;
        } catch (\Throwable) {
            // Table does not exist yet — created below.
        }

        $schema = new Schema();
        $table = $schema->createTable('schema_migrations');
        $table->addColumn('version', 'string', ['length' => 190]);
        $table->addColumn('ran_at', 'string', ['length' => 40]);
        $table->setPrimaryKey(['version']);

        foreach ($schema->toSql($conn->getDatabasePlatform()) as $sql) {
            $conn->executeStatement($sql);
        }

        $this->migrationsTableReady = true;
    }

    private function ran(string $version): bool
    {
        return $this->ranAt($version) !== null;
    }

    private function ranAt(string $version): ?string
    {
        $this->warmRan();

        return $this->ranCache[$version] ?? null;
    }

    /** Loads all schema_migrations rows in one SELECT (instead of N× WHERE version = ?). */
    private function warmRan(): void
    {
        if ($this->ranCache !== null) {
            return;
        }

        $this->ensureMigrationsTable();
        $this->ranCache = [];

        try {
            $rows = $this->em->getConnection()->fetchAllAssociative(
                'SELECT version, ran_at FROM schema_migrations /* gereq:warm */'
            );
            foreach ($rows as $row) {
                $version = (string) ($row['version'] ?? '');
                $ranAt = $row['ran_at'] ?? null;
                if ($version !== '' && is_string($ranAt) && $ranAt !== '') {
                    $this->ranCache[$version] = $ranAt;
                }
            }
        } catch (\Throwable) {
            $this->ranCache = [];
        }
    }

    /**
     * Drop on-disk Doctrine metadata when entities change (app.debug=false).
     * Does not reload already-warmed ClassMetadata in this EM — DROP filters cover that.
     */
    private function refreshMetadataCache(): void
    {
        try {
            $cache = $this->em->getConfiguration()->getMetadataCache();
            if ($cache instanceof \Psr\Cache\CacheItemPoolInterface) {
                $cache->clear();
            }
        } catch (\Throwable) {
            // cache optional
        }
    }
}
