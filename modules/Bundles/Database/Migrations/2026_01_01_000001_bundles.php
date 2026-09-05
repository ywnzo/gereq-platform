<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('product_bundles')) {
            $table = $schema->createTable('product_bundles');
            $table->addColumn('id', Types::STRING)->setLength(36);
            $table->addColumn('code', Types::STRING)->setLength(64);
            $table->addColumn('name', Types::STRING)->setLength(190);
            $table->addColumn('description', Types::TEXT)->setNotnull(false);
            $table->addColumn('discount_type', Types::STRING)->setLength(16)->setDefault('none');
            $table->addColumn('discount_value', Types::DECIMAL)->setPrecision(14)->setScale(2)->setDefault('0.00');
            $table->addColumn('currency', Types::STRING)->setLength(3)->setNotnull(false);
            $table->addColumn('min_items', Types::INTEGER)->setDefault(1);
            $table->addColumn('max_items', Types::INTEGER)->setNotnull(false);
            $table->addColumn('max_same_product', Types::INTEGER)->setNotnull(false);
            $table->addColumn('is_active', Types::BOOLEAN)->setDefault(true);
            $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['code'], 'uniq_product_bundles_code');
            $table->addIndex(['is_active'], 'idx_product_bundles_active');
        }

        if (!$schema->hasTable('product_bundle_items')) {
            $items = $schema->createTable('product_bundle_items');
            $items->addColumn('id', Types::STRING)->setLength(36);
            $items->addColumn('bundle_id', Types::STRING)->setLength(36);
            $items->addColumn('product_id', Types::STRING)->setLength(36);
            $items->addColumn('title', Types::STRING)->setLength(190);
            $items->addColumn('qty', Types::INTEGER)->setDefault(1);
            $items->addColumn('sort_order', Types::INTEGER)->setDefault(0);
            $items->setPrimaryKey(['id']);
            $items->addIndex(['bundle_id'], 'idx_product_bundle_items_bundle');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('product_bundle_items')) {
            $schema->dropTable('product_bundle_items');
        }
        if ($schema->hasTable('product_bundles')) {
            $schema->dropTable('product_bundles');
        }
    }
};
