<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('cms_form_uploads');
        $table->addColumn('scan_status', Types::STRING, ['length' => 16, 'default' => 'pending']);
        $table->addColumn('scan_attempts', Types::INTEGER, ['default' => 0]);
        foreach (['scan_next_at', 'scan_lease_until', 'scanned_at'] as $column) {
            $table->addColumn($column, Types::STRING, ['length' => 19, 'notnull' => false]);
        }
        $table->addColumn('scan_lease_token', Types::STRING, ['length' => 36, 'notnull' => false]);
        $table->addColumn('scan_hash', Types::STRING, ['length' => 64, 'notnull' => false]);
        foreach (['scan_engine', 'scan_definitions', 'scan_reason'] as $column) {
            $table->addColumn($column, Types::STRING, ['length' => 120, 'default' => '']);
        }
        $table->addIndex(['scan_status', 'scan_next_at'], 'idx_cms_form_uploads_scan');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('cms_form_uploads');
        $table->dropIndex('idx_cms_form_uploads_scan');
        foreach (['scan_status', 'scan_attempts', 'scan_next_at', 'scan_lease_until', 'scanned_at', 'scan_lease_token', 'scan_hash', 'scan_engine', 'scan_definitions', 'scan_reason'] as $column) {
            $table->dropColumn($column);
        }
    }
};
