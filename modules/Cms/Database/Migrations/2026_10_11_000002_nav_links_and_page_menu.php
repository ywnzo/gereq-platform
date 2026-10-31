<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Ramsey\Uuid\Uuid;

/**
 * Page menu_location + cms_nav_links; migrate legacy SiteMenuItem rows.
 */
return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('cms_pages')) {
            $pages = $schema->getTable('cms_pages');
            if (!$pages->hasColumn('menu_location')) {
                $pages->addColumn('menu_location', Types::STRING, [
                    'length' => 32,
                    'notnull' => false,
                ]);
            }
            if (!$pages->hasColumn('menu_sort_order')) {
                $pages->addColumn('menu_sort_order', Types::INTEGER, [
                    'notnull' => true,
                    'default' => 0,
                ]);
            }
        }

        if (!$schema->hasTable('cms_nav_links')) {
            $links = $schema->createTable('cms_nav_links');
            $links->addColumn('id', Types::STRING, ['length' => 36]);
            $links->addColumn('title', Types::STRING, ['length' => 190]);
            $links->addColumn('url', Types::STRING, ['length' => 2048]);
            $links->addColumn('status', Types::STRING, ['length' => 32]);
            $links->addColumn('menu_location', Types::STRING, ['length' => 32, 'notnull' => false]);
            $links->addColumn('menu_sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $links->addColumn('published_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $links->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $links->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $links->setPrimaryKey(['id']);
            $links->addIndex(['menu_location', 'menu_sort_order'], 'idx_cms_nav_links_menu');
            $links->addIndex(['status'], 'idx_cms_nav_links_status');
        }
    }

    public function afterUp(Connection $connection): void
    {
        $sm = $connection->createSchemaManager();
        if (!$sm->tablesExist(['cms_site_menu_items', 'cms_site_menus', 'cms_nav_links', 'cms_pages'])) {
            return;
        }

        $existingLinks = (int) $connection->fetchOne('SELECT COUNT(*) FROM cms_nav_links');
        $pagesWithMenu = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM cms_pages WHERE menu_location IS NOT NULL AND menu_location <> ''",
        );
        if ($existingLinks > 0 || $pagesWithMenu > 0) {
            return;
        }

        $rows = $connection->fetchAllAssociative(
            'SELECT i.label, i.url, i.page_id, i.sort_order, m.location
             FROM cms_site_menu_items i
             INNER JOIN cms_site_menus m ON m.id = i.menu_id
             ORDER BY i.sort_order ASC',
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $location = (string) ($row['location'] ?? '');
            if (!in_array($location, ['header', 'footer', 'custom'], true)) {
                continue;
            }
            $pageId = trim((string) ($row['page_id'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            $sort = (int) ($row['sort_order'] ?? 0);

            if ($pageId !== '') {
                $connection->executeStatement(
                    'UPDATE cms_pages SET menu_location = ?, menu_sort_order = ? WHERE id = ? AND (menu_location IS NULL OR menu_location = \'\')',
                    [$location, $sort, $pageId],
                );
                continue;
            }

            if ($url === '') {
                continue;
            }

            $connection->insert('cms_nav_links', [
                'id' => Uuid::uuid7()->toString(),
                'title' => (string) ($row['label'] ?? $url),
                'url' => $url,
                'status' => 'published',
                'menu_location' => $location,
                'menu_sort_order' => $sort,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('cms_nav_links')) {
            $schema->dropTable('cms_nav_links');
        }
        if ($schema->hasTable('cms_pages')) {
            $pages = $schema->getTable('cms_pages');
            if ($pages->hasColumn('menu_location')) {
                $pages->dropColumn('menu_location');
            }
            if ($pages->hasColumn('menu_sort_order')) {
                $pages->dropColumn('menu_sort_order');
            }
        }
    }
};
