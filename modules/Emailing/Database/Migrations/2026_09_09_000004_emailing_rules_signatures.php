<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('emailing_accounts')) {
            $accounts = $schema->getTable('emailing_accounts');
            if (!$accounts->hasColumn('signature_html')) {
                $accounts->addColumn('signature_html', Types::TEXT, ['notnull' => false]);
            }
        }

        if (!$schema->hasTable('emailing_rules')) {
            $table = $schema->createTable('emailing_rules');
            $table->addColumn('id', Types::STRING, ['length' => 36]);
            $table->addColumn('account_id', Types::STRING, ['length' => 36, 'notnull' => false]);
            $table->addColumn('name', Types::STRING, ['length' => 190]);
            $table->addColumn('is_active', Types::BOOLEAN);
            $table->addColumn('priority', Types::INTEGER);
            $table->addColumn('stop_processing', Types::BOOLEAN);
            $table->addColumn('conditions', Types::JSON);
            $table->addColumn('actions', Types::JSON);
            $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['account_id'], 'idx_emailing_rules_account');
            $table->addIndex(['is_active', 'priority'], 'idx_emailing_rules_active_prio');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('emailing_rules')) {
            $schema->dropTable('emailing_rules');
        }
        if ($schema->hasTable('emailing_accounts')) {
            $accounts = $schema->getTable('emailing_accounts');
            if ($accounts->hasColumn('signature_html')) {
                $accounts->dropColumn('signature_html');
            }
        }
    }
};
