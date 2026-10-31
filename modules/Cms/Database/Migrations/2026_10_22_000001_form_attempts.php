<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('cms_form_attempts')) {
            return;
        }
        $table = $schema->createTable('cms_form_attempts');
        $table->addColumn('id', Types::STRING, ['length' => 36]);
        $table->addColumn('page_slug', Types::STRING, ['length' => 180]);
        $table->addColumn('form_id', Types::STRING, ['length' => 80]);
        $table->addColumn('http_status', Types::INTEGER);
        $table->addColumn('outcome', Types::STRING, ['length' => 80]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['created_at'], 'idx_cms_form_attempts_created');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('cms_form_attempts')) {
            $schema->dropTable('cms_form_attempts');
        }
    }
};
