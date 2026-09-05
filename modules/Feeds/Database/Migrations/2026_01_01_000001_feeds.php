<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('feed_definitions')) {
            return;
        }

        $table = $schema->createTable('feed_definitions');
        $table->addColumn('id', Types::STRING)->setLength(36);
        $table->addColumn('code', Types::STRING)->setLength(64);
        $table->addColumn('name', Types::STRING)->setLength(190);
        $table->addColumn('driver', Types::STRING)->setLength(32)->setDefault('google_merchant');
        $table->addColumn('format', Types::STRING)->setLength(16)->setDefault('xml');
        $table->addColumn('is_active', Types::BOOLEAN)->setDefault(true);
        $table->addColumn('config', Types::JSON)->setNotnull(false);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['code'], 'uniq_feed_definitions_code');
        $table->addIndex(['is_active'], 'idx_feed_definitions_active');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('feed_definitions')) {
            $schema->dropTable('feed_definitions');
        }
    }
};
