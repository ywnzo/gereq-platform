<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('coupons')) {
            return;
        }

        $table = $schema->createTable('coupons');
        $table->addColumn('id', Types::STRING)->setLength(36);
        $table->addColumn('code', Types::STRING)->setLength(64);
        $table->addColumn('name', Types::STRING)->setLength(190);
        $table->addColumn('type', Types::STRING)->setLength(16);
        $table->addColumn('value', Types::DECIMAL)->setPrecision(14)->setScale(2)->setDefault('0.00');
        $table->addColumn('currency', Types::STRING)->setLength(3)->setNotnull(false);
        $table->addColumn('min_order', Types::DECIMAL)->setPrecision(14)->setScale(2)->setNotnull(false);
        $table->addColumn('max_uses', Types::INTEGER)->setNotnull(false);
        $table->addColumn('used_count', Types::INTEGER)->setDefault(0);
        $table->addColumn('starts_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
        $table->addColumn('ends_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
        $table->addColumn('is_active', Types::BOOLEAN)->setDefault(true);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['code'], 'uniq_coupons_code');
        $table->addIndex(['is_active'], 'idx_coupons_active');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('coupons')) {
            $schema->dropTable('coupons');
        }
    }
};
