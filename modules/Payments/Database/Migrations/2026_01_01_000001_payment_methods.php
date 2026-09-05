<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('payment_methods')) {
            return;
        }

        $table = $schema->createTable('payment_methods');
        $table->addColumn('id', Types::STRING)->setLength(36);
        $table->addColumn('code', Types::STRING)->setLength(64);
        $table->addColumn('name', Types::STRING)->setLength(190);
        $table->addColumn('type', Types::STRING)->setLength(32);
        $table->addColumn('provider', Types::STRING)->setLength(64)->setNotnull(false);
        $table->addColumn('fee_fixed', Types::DECIMAL)->setPrecision(14)->setScale(2)->setDefault('0.00');
        $table->addColumn('fee_percent', Types::DECIMAL)->setPrecision(8)->setScale(4)->setDefault('0.0000');
        $table->addColumn('currency', Types::STRING)->setLength(3)->setDefault('CZK');
        $table->addColumn('instructions', Types::TEXT)->setNotnull(false);
        $table->addColumn('sort_order', Types::INTEGER)->setDefault(0);
        $table->addColumn('is_active', Types::BOOLEAN)->setDefault(true);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['code'], 'uniq_payment_methods_code');
        $table->addIndex(['is_active', 'sort_order'], 'idx_payment_methods_active_sort');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('payment_methods')) {
            $schema->dropTable('payment_methods');
        }
    }
};
