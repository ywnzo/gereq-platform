<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/** Pages: menu_location → menu_locations (JSON, multi-select tags). */
return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('cms_pages')) {
            return;
        }

        $pages = $schema->getTable('cms_pages');
        if (!$pages->hasColumn('menu_locations')) {
            $pages->addColumn('menu_locations', Types::JSON, [
                'notnull' => true,
                'default' => '[]',
            ]);
        }
    }

    public function afterUp(Connection $connection): void
    {
        $sm = $connection->createSchemaManager();
        if (!$sm->tablesExist(['cms_pages'])) {
            return;
        }

        $cols = array_map(
            static fn ($c) => $c->getName(),
            $sm->listTableColumns('cms_pages'),
        );
        if (!in_array('menu_locations', $cols, true)) {
            return;
        }

        if (in_array('menu_location', $cols, true)) {
            $rows = $connection->fetchAllAssociative(
                'SELECT id, menu_location FROM cms_pages',
            );
            foreach ($rows as $row) {
                $location = trim((string) ($row['menu_location'] ?? ''));
                $json = $location !== '' && in_array($location, ['header', 'footer', 'custom'], true)
                    ? json_encode([$location], JSON_THROW_ON_ERROR)
                    : '[]';
                $connection->executeStatement(
                    'UPDATE cms_pages SET menu_locations = ? WHERE id = ?',
                    [$json, $row['id']],
                );
            }

            $schema = $sm->introspectSchema();
            $table = $schema->getTable('cms_pages');
            if ($table->hasColumn('menu_location')) {
                $table->dropColumn('menu_location');
            }
            $from = $sm->introspectSchema();
            $diff = $sm->createComparator()->compareSchemas($from, $schema);
            if (!$diff->isEmpty()) {
                $sm->alterSchema($diff);
            }
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('cms_pages')) {
            return;
        }
        $pages = $schema->getTable('cms_pages');
        if ($pages->hasColumn('menu_locations')) {
            $pages->dropColumn('menu_locations');
        }
        if (!$pages->hasColumn('menu_location')) {
            $pages->addColumn('menu_location', Types::STRING, [
                'length' => 32,
                'notnull' => false,
            ]);
        }
    }
};
