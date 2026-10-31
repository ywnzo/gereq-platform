<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/** Nav links: menu_location → menu_locations (JSON, multi-select). */
return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('cms_nav_links')) {
            return;
        }

        $links = $schema->getTable('cms_nav_links');
        if (!$links->hasColumn('menu_locations')) {
            $links->addColumn('menu_locations', Types::JSON, [
                'notnull' => true,
                'default' => '[]',
            ]);
        }
    }

    public function afterUp(Connection $connection): void
    {
        $sm = $connection->createSchemaManager();
        if (!$sm->tablesExist(['cms_nav_links'])) {
            return;
        }

        $cols = array_map(
            static fn ($c) => $c->getName(),
            $sm->listTableColumns('cms_nav_links'),
        );
        if (!in_array('menu_locations', $cols, true)) {
            return;
        }

        if (in_array('menu_location', $cols, true)) {
            $rows = $connection->fetchAllAssociative(
                'SELECT id, menu_location FROM cms_nav_links',
            );
            foreach ($rows as $row) {
                $location = trim((string) ($row['menu_location'] ?? ''));
                $json = $location !== '' && in_array($location, ['header', 'footer', 'custom'], true)
                    ? json_encode([$location], JSON_THROW_ON_ERROR)
                    : '[]';
                $connection->executeStatement(
                    'UPDATE cms_nav_links SET menu_locations = ? WHERE id = ?',
                    [$json, $row['id']],
                );
            }

            $schema = $sm->introspectSchema();
            $table = $schema->getTable('cms_nav_links');
            if ($table->hasIndex('idx_cms_nav_links_menu')) {
                $table->dropIndex('idx_cms_nav_links_menu');
            }
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
        if (!$schema->hasTable('cms_nav_links')) {
            return;
        }
        $links = $schema->getTable('cms_nav_links');
        if ($links->hasColumn('menu_locations')) {
            $links->dropColumn('menu_locations');
        }
        if (!$links->hasColumn('menu_location')) {
            $links->addColumn('menu_location', Types::STRING, [
                'length' => 32,
                'notnull' => false,
            ]);
        }
    }
};
