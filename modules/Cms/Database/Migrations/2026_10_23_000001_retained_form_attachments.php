<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('cms_form_uploads');
        $table->addColumn('action_id', Types::STRING, ['length' => 120, 'notnull' => false]);
        $table->addColumn('receipt_id', Types::STRING, ['length' => 80, 'notnull' => false]);
        $table->addIndex(['action_id', 'receipt_id'], 'idx_cms_form_uploads_record');
    }

    public function afterUp(Connection $connection): void
    {
        $rows = $connection->executeQuery('SELECT u.id, a.action_id, a.receipt_id FROM cms_form_uploads u INNER JOIN cms_form_attempts a ON a.id = u.attempt_id');
        while (($row = $rows->fetchAssociative()) !== false) {
            $connection->update('cms_form_uploads', ['action_id' => $row['action_id'], 'receipt_id' => $row['receipt_id']], ['id' => $row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('cms_form_uploads');
        $table->dropIndex('idx_cms_form_uploads_record');
        $table->dropColumn('receipt_id');
        $table->dropColumn('action_id');
    }
};
