<?php

declare(strict_types=1);

namespace Gereq\Database;

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
        $sm = $conn->createSchemaManager();
        if ($sm->tablesExist(['schema_migrations'])) {
            $conn->executeStatement('DELETE FROM schema_migrations');
            $this->ranCache = [];
            $this->migrationsTableReady = true;
            $messages[] = 'Tabulka schema_migrations vyčištěna.';
        }

        return array_merge($messages, $this->migrate());
    }

    /**
     * @return list<string>
     */
    public function migrate(): array
    {
        $messages = [];
        $this->ensureMigrationsTable();

        // Nejdřív souborové migrace (mají DEFAULT / backfill), teprve potom SchemaTool.
        foreach ($this->modules->discover() as $manifest) {
            $messages = array_merge($messages, $this->migrateModule($manifest->id, syncSchema: false));
        }

        return array_merge($messages, $this->syncSchema());
    }

    /**
     * Sync Doctrine entity metadata to the database.
     *
     * @return list<string>
     */
    public function syncSchema(): array
    {
        $messages = [];
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
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

        // Porovnávat jen entity tabulky — jinak SchemaTool DROPNE např. schema_migrations.
        $config->setSchemaAssetsFilter(static function (string|\Doctrine\DBAL\Schema\AbstractAsset $asset) use ($managed): bool {
            $name = is_string($asset) ? $asset : $asset->getName();

            return isset($managed[strtolower($name)]);
        });

        try {
            $tool = new SchemaTool($this->em);
            $sql = array_values(array_filter(
                $tool->getUpdateSchemaSQL($metadata),
                static function (string $statement) use ($managed): bool {
                    // Povolit DROP jen pro entity tabulky / SQLite __temp__ rebuild.
                    // Jinak SchemaTool umí smazat např. schema_migrations.
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

            foreach ($files as $file) {
                $version = $manifest->id . ':' . basename($file, '.php');
                if ($this->ran($version)) {
                    continue;
                }

                $migration = require $file;
                if (!is_object($migration) || !method_exists($migration, 'up')) {
                    continue;
                }

                $fromSchema = $schemaManager->introspectSchema();
                $toSchema = $schemaManager->introspectSchema();
                $migration->up($toSchema);

                $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
                if (!$diff->isEmpty()) {
                    $schemaManager->alterSchema($diff);
                }

                if (method_exists($migration, 'afterUp')) {
                    $migration->afterUp($conn);
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

    private function ensureMigrationsTable(): void
    {
        if ($this->migrationsTableReady) {
            return;
        }

        $sm = $this->em->getConnection()->createSchemaManager();
        if ($sm->tablesExist(['schema_migrations'])) {
            $this->migrationsTableReady = true;

            return;
        }

        $schema = new \Doctrine\DBAL\Schema\Schema();
        $table = $schema->createTable('schema_migrations');
        $table->addColumn('version', 'string', ['length' => 190]);
        $table->addColumn('ran_at', 'string', ['length' => 40]);
        $table->setPrimaryKey(['version']);

        foreach ($schema->toSql($this->em->getConnection()->getDatabasePlatform()) as $sql) {
            $this->em->getConnection()->executeStatement($sql);
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

    /** Jedním SELECT načte všechny záznamy schema_migrations (místo N× WHERE version = ?). */
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
}
