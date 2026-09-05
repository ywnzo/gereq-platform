<?php

declare(strict_types=1);

namespace Gereq\Database;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Gereq\Module\ModuleManager;

final class Migrator
{
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
                static fn (string $statement): bool => !preg_match('/^\s*DROP\s+TABLE\b/i', $statement),
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

                $conn->executeStatement(
                    'INSERT INTO schema_migrations (version, ran_at) VALUES (?, ?)',
                    [$version, (new \DateTimeImmutable())->format('c')]
                );
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
        $this->ensureMigrationsTable();
        $manifest = $this->modules->manifest($moduleId);
        if ($manifest === null) {
            return [];
        }

        $dir = $manifest->path . '/Database/Migrations';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.php') ?: [];
        sort($files);
        $items = [];

        foreach ($files as $file) {
            $basename = basename($file, '.php');
            $version = $manifest->id . ':' . $basename;
            $ranAt = $this->ranAt($version);
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
        $sm = $this->em->getConnection()->createSchemaManager();
        if ($sm->tablesExist(['schema_migrations'])) {
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
    }

    private function ran(string $version): bool
    {
        return $this->ranAt($version) !== null;
    }

    private function ranAt(string $version): ?string
    {
        $result = $this->em->getConnection()->fetchAssociative(
            'SELECT version, ran_at FROM schema_migrations WHERE version = ?',
            [$version]
        );

        if ($result === false || $result === null) {
            return null;
        }

        $ranAt = $result['ran_at'] ?? null;

        return is_string($ranAt) && $ranAt !== '' ? $ranAt : null;
    }
}
