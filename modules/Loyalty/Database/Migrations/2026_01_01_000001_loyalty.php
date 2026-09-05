<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('loyalty_tiers')) {
            $table = $schema->createTable('loyalty_tiers');
            $table->addColumn('id', Types::STRING)->setLength(36);
            $table->addColumn('code', Types::STRING)->setLength(64);
            $table->addColumn('name', Types::STRING)->setLength(190);
            $table->addColumn('min_spend', Types::DECIMAL)->setPrecision(14)->setScale(2)->setDefault('0.00');
            $table->addColumn('discount_percent', Types::DECIMAL)->setPrecision(8)->setScale(2)->setDefault('0.00');
            $table->addColumn('sort_order', Types::INTEGER)->setDefault(0);
            $table->addColumn('is_active', Types::BOOLEAN)->setDefault(true);
            $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['code'], 'uniq_loyalty_tiers_code');
            $table->addIndex(['is_active', 'min_spend'], 'idx_loyalty_tiers_active_spend');
        }

        if (!$schema->hasTable('loyalty_accounts')) {
            $accounts = $schema->createTable('loyalty_accounts');
            $accounts->addColumn('id', Types::STRING)->setLength(36);
            $accounts->addColumn('customer_id', Types::STRING)->setLength(36);
            $accounts->addColumn('tier_id', Types::STRING)->setLength(36)->setNotnull(false);
            $accounts->addColumn('points', Types::INTEGER)->setDefault(0);
            $accounts->addColumn('lifetime_spend', Types::DECIMAL)->setPrecision(14)->setScale(2)->setDefault('0.00');
            $accounts->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $accounts->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $accounts->setPrimaryKey(['id']);
            $accounts->addUniqueIndex(['customer_id'], 'uniq_loyalty_accounts_customer');
            $accounts->addIndex(['tier_id'], 'idx_loyalty_accounts_tier');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('loyalty_accounts')) {
            $schema->dropTable('loyalty_accounts');
        }
        if ($schema->hasTable('loyalty_tiers')) {
            $schema->dropTable('loyalty_tiers');
        }
    }
};
