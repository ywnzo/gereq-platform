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
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $sql = $tool->getUpdateSchemaSQL($metadata);

        if ($sql !== []) {
            $tool->updateSchema($metadata);
            $messages[] = 'Core schema aktualizováno (' . count($sql) . ' příkazů).';
        } else {
            $messages[] = 'Core schema je aktuální.';
        }

        $this->ensureMigrationsTable();

        foreach ($this->modules->discover() as $manifest) {
            $dir = $manifest->path . '/Database/Migrations';
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . '/*.php') ?: [];
            sort($files);

            foreach ($files as $file) {
                $version = $manifest->id . ':' . basename($file, '.php');
                if ($this->ran($version)) {
                    continue;
                }

                $migration = require $file;
                if (!is_object($migration) || !method_exists($migration, 'up')) {
                    continue;
                }

                $schema = $this->em->getConnection()->createSchemaManager()->introspectSchema();
                $migration->up($schema);
                $this->em->getConnection()->executeStatement(
                    'INSERT INTO schema_migrations (version, ran_at) VALUES (?, ?)',
                    [$version, (new \DateTimeImmutable())->format('c')]
                );
                $messages[] = 'Migrace ' . $version;
            }
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
