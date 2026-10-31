<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('cms_form_uploads');
        $table->addColumn('id', Types::STRING, ['length' => 36]);
        $table->setPrimaryKey(['id']);
        $table->addColumn('attempt_id', Types::STRING, ['length' => 36]);
        $table->addIndex(['attempt_id']);
        $table->addColumn('field_name', Types::STRING, ['length' => 80]);
        $table->addColumn('filename', Types::STRING, ['length' => 180]);
        $table->addColumn('mime_type', Types::STRING, ['length' => 80]);
        $table->addColumn('content', Types::BLOB);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['default' => 'CURRENT_TIMESTAMP']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('cms_form_uploads');
    }
};
