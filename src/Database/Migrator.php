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
     * @return list<array{version: string, ran: bool}>
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();
        $items = [];

        foreach ($this->modules->discover() as $manifest) {
            $dir = $manifest->path . '/Database/Migrations';
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                $version = $manifest->id . ':' . basename($file, '.php');
                $items[] = ['version' => $version, 'ran' => $this->ran($version)];
            }
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
        $result = $this->em->getConnection()->fetchOne(
            'SELECT version FROM schema_migrations WHERE version = ?',
            [$version]
        );

        return $result !== false && $result !== null;
    }
}
