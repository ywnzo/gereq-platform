<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('product_option_groups')) {
            $table = $schema->getTable('product_option_groups');

            if (!$table->hasColumn('scope_type')) {
                $table->addColumn('scope_type', Types::STRING)
                    ->setLength(16)
                    ->setDefault('product')
                    ->setNotnull(true);
            }
            if (!$table->hasColumn('scope_id')) {
                $table->addColumn('scope_id', Types::STRING)
                    ->setLength(36)
                    ->setNotnull(false);
            }

            if ($table->hasIndex('uniq_product_option_groups_code')) {
                $table->dropIndex('uniq_product_option_groups_code');
            }
            if ($table->hasIndex('idx_product_option_groups_product')) {
                $table->dropIndex('idx_product_option_groups_product');
            }

            if (!$table->hasIndex('uniq_product_option_groups_scope_code')) {
                $table->addUniqueIndex(['scope_type', 'scope_id', 'code'], 'uniq_product_option_groups_scope_code');
            }
            if (!$table->hasIndex('idx_product_option_groups_scope')) {
                $table->addIndex(['scope_type', 'scope_id'], 'idx_product_option_groups_scope');
            }
        }

        if ($schema->hasTable('products') && !$schema->getTable('products')->hasColumn('inherit_category_options')) {
            $schema->getTable('products')->addColumn('inherit_category_options', Types::BOOLEAN)
                ->setDefault(false)
                ->setNotnull(true);
        }
    }

    public function afterUp(Connection $connection): void
    {
        $sm = $connection->createSchemaManager();
        if (!$sm->tablesExist(['product_option_groups'])) {
            return;
        }

        $columns = array_map(
            static fn ($c) => $c->getName(),
            $sm->listTableColumns('product_option_groups'),
        );

        if (in_array('product_id', $columns, true) && in_array('scope_id', $columns, true)) {
            $connection->executeStatement(
                "UPDATE product_option_groups
                 SET scope_type = 'product', scope_id = product_id
                 WHERE scope_id IS NULL OR scope_id = ''",
            );
            try {
                $connection->executeStatement('ALTER TABLE product_option_groups DROP COLUMN product_id');
            } catch (\Throwable) {
                // SQLite / already dropped
            }
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('products') && $schema->getTable('products')->hasColumn('inherit_category_options')) {
            $schema->getTable('products')->dropColumn('inherit_category_options');
        }
    }
};
