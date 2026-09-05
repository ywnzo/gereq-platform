<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('product_discounts')) {
            return;
        }

        $table = $schema->createTable('product_discounts');
        $table->addColumn('id', Types::STRING)->setLength(36);
        $table->addColumn('product_id', Types::STRING)->setLength(36);
        $table->addColumn('kind', Types::STRING)->setLength(16);
        $table->addColumn('value', Types::DECIMAL)->setPrecision(14)->setScale(2)->setDefault('0.00');
        $table->addColumn('description', Types::STRING)->setLength(190)->setNotnull(false);
        $table->addColumn('starts_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
        $table->addColumn('ends_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
        $table->addColumn('is_active', Types::BOOLEAN)->setDefault(true);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['product_id'], 'uniq_product_discounts_product');
        $table->addIndex(['is_active', 'starts_at', 'ends_at'], 'idx_product_discounts_active_window');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('product_discounts')) {
            $schema->dropTable('product_discounts');
        }
    }
};
