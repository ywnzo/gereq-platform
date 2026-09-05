<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('product_reviews')) {
            return;
        }

        $table = $schema->getTable('product_reviews');

        // SQLite: NOT NULL u existujících řádků vyžaduje DEFAULT.
        if (!$table->hasColumn('source')) {
            $table->addColumn('source', Types::STRING)
                ->setLength(32)
                ->setNotnull(true)
                ->setDefault('local');
        }
        if (!$table->hasColumn('external_id')) {
            $table->addColumn('external_id', Types::STRING)
                ->setLength(190)
                ->setNotnull(false);
        }
        if (!$table->hasColumn('external_url')) {
            $table->addColumn('external_url', Types::STRING)
                ->setLength(500)
                ->setNotnull(false);
        }
        if (!$table->hasIndex('idx_product_reviews_source')) {
            $table->addIndex(['source'], 'idx_product_reviews_source');
        }
        if (!$table->hasIndex('uniq_product_reviews_external')) {
            $table->addUniqueIndex(['source', 'external_id'], 'uniq_product_reviews_external');
        }
    }

    public function afterUp(Connection $connection): void
    {
        // Po částečném SchemaTool ADD COLUMN můžou mít staré řádky source = NULL.
        if (!$connection->createSchemaManager()->tablesExist(['product_reviews'])) {
            return;
        }

        $columns = $connection->createSchemaManager()->listTableColumns('product_reviews');
        if (!isset($columns['source'])) {
            return;
        }

        $connection->executeStatement(
            "UPDATE product_reviews SET source = 'local' WHERE source IS NULL OR source = ''"
        );
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('product_reviews')) {
            return;
        }

        $table = $schema->getTable('product_reviews');
        if ($table->hasIndex('uniq_product_reviews_external')) {
            $table->dropIndex('uniq_product_reviews_external');
        }
        if ($table->hasIndex('idx_product_reviews_source')) {
            $table->dropIndex('idx_product_reviews_source');
        }
        foreach (['external_url', 'external_id', 'source'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }
    }
};
