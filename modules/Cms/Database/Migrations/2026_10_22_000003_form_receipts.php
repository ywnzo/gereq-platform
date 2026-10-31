<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('cms_form_attempts');
        $table->addColumn('action_id', Types::STRING, ['length' => 120, 'notnull' => false]);
        $table->addColumn('receipt_id', Types::STRING, ['length' => 80, 'notnull' => false]);
        $table->addColumn('retry_count', Types::INTEGER, ['default' => 0]);
        $table->addColumn('last_retry_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        foreach (['action_id', 'receipt_id', 'retry_count', 'last_retry_at'] as $column) {
            $schema->getTable('cms_form_attempts')->dropColumn($column);
        }
    }
};
