<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kdeaco_event_feeds')) {
            return;
        }
        $table = $schema->createTable('kdeaco_event_feeds');
        $table->addColumn('id', Types::STRING)->setLength(36);
        $table->addColumn('feed_key', Types::STRING)->setLength(40);
        $table->addColumn('name', Types::STRING)->setLength(120);
        $table->addColumn('driver', Types::STRING)->setLength(32);
        $table->addColumn('url', Types::STRING)->setLength(500);
        $table->addColumn('region', Types::STRING)->setLength(40);
        $table->addColumn('organizer', Types::STRING)->setLength(190)->setNotnull(false);
        $table->addColumn('enabled', Types::BOOLEAN)->setDefault(true);
        $table->addColumn('sort_order', Types::INTEGER)->setDefault(0);
        $table->addColumn('config', Types::JSON)->setNotnull(false);
        $table->addColumn('last_synced_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
        $table->addColumn('last_error', Types::TEXT)->setNotnull(false);
        $table->addColumn('last_fetched', Types::INTEGER)->setDefault(0);
        $table->addColumn('last_created', Types::INTEGER)->setDefault(0);
        $table->addColumn('last_updated', Types::INTEGER)->setDefault(0);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['feed_key'], 'uniq_kdeaco_event_feeds_key');
        $table->addIndex(['enabled'], 'idx_kdeaco_event_feeds_enabled');
    }

    public function afterUp(Connection $conn): void
    {
        // seeded by EventFeeds::ensureDefaults() on first import / admin open
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kdeaco_event_feeds')) {
            $schema->dropTable('kdeaco_event_feeds');
        }
    }
};
