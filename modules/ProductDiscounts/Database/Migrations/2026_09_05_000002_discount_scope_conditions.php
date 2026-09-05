<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('product_discounts')) {
            return;
        }
        $table = $schema->getTable('product_discounts');

        if (!$table->hasColumn('scope_type')) {
            $table->addColumn('scope_type', Types::STRING)->setLength(16)->setDefault('product');
        }
        if (!$table->hasColumn('scope_id')) {
            $table->addColumn('scope_id', Types::STRING)->setLength(36)->setNotnull(false);
        }
        if (!$table->hasColumn('condition_type')) {
            $table->addColumn('condition_type', Types::STRING)->setLength(32)->setDefault('none');
        }
        if (!$table->hasColumn('condition_value')) {
            $table->addColumn('condition_value', Types::INTEGER)->setNotnull(false);
        }

        if ($table->hasColumn('product_id')) {
            $col = $table->getColumn('product_id');
            $col->setNotnull(false);
        }

        if ($table->hasIndex('uniq_product_discounts_product')) {
            $table->dropIndex('uniq_product_discounts_product');
        }
        if (!$table->hasIndex('idx_product_discounts_scope')) {
            $table->addIndex(['scope_type', 'scope_id'], 'idx_product_discounts_scope');
        }
    }

    public function afterUp(\Doctrine\DBAL\Connection $connection): void
    {
        // Backfill scope from legacy product_id.
        $connection->executeStatement(
            "UPDATE product_discounts
             SET scope_type = 'product',
                 scope_id = product_id,
                 condition_type = COALESCE(NULLIF(condition_type, ''), 'none')
             WHERE scope_id IS NULL OR scope_id = ''"
        );
        // Ensure scope_id is required going forward.
        try {
            $connection->executeStatement('ALTER TABLE product_discounts ALTER COLUMN scope_id SET NOT NULL');
        } catch (\Throwable) {
            // SQLite / drivers without ALTER COLUMN constraints — ignore.
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('product_discounts')) {
            return;
        }
        $table = $schema->getTable('product_discounts');
        if ($table->hasIndex('idx_product_discounts_scope')) {
            $table->dropIndex('idx_product_discounts_scope');
        }
        foreach (['condition_value', 'condition_type', 'scope_id', 'scope_type'] as $col) {
            if ($table->hasColumn($col)) {
                $table->dropColumn($col);
            }
        }
        if (!$table->hasIndex('uniq_product_discounts_product') && $table->hasColumn('product_id')) {
            $table->addUniqueIndex(['product_id'], 'uniq_product_discounts_product');
        }
    }
};
