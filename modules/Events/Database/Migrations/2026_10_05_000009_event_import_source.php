<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kdeaco_events')) {
            return;
        }
        $table = $schema->getTable('kdeaco_events');
        if (!$table->hasColumn('source')) {
            $table->addColumn('source', Types::STRING)->setLength(40)->setNotnull(false);
        }
        if (!$table->hasColumn('source_id')) {
            $table->addColumn('source_id', Types::STRING)->setLength(190)->setNotnull(false);
        }
        if (!$table->hasColumn('synced_at')) {
            $table->addColumn('synced_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
        }
        if (!$table->hasIndex('idx_kdeaco_events_source')) {
            $table->addIndex(['source'], 'idx_kdeaco_events_source');
        }
        if (!$table->hasIndex('uniq_kdeaco_events_source')) {
            $table->addUniqueIndex(['source', 'source_id'], 'uniq_kdeaco_events_source');
        }
    }

    /** Safety net — Schema diff can miss columns when metadata/DB drift. */
    public function afterUp(Connection $conn): void
    {
        $platform = $conn->getDatabasePlatform();
        if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            $conn->executeStatement('ALTER TABLE kdeaco_events ADD COLUMN IF NOT EXISTS source varchar(40) NULL');
            $conn->executeStatement('ALTER TABLE kdeaco_events ADD COLUMN IF NOT EXISTS source_id varchar(190) NULL');
            $conn->executeStatement('ALTER TABLE kdeaco_events ADD COLUMN IF NOT EXISTS synced_at timestamp(0) without time zone NULL');
            $conn->executeStatement('CREATE INDEX IF NOT EXISTS idx_kdeaco_events_source ON kdeaco_events (source)');
            $conn->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS uniq_kdeaco_events_source ON kdeaco_events (source, source_id)');

            return;
        }

        // SQLite / others: best-effort try/catch.
        foreach ([
            'ALTER TABLE kdeaco_events ADD COLUMN source varchar(40) NULL',
            'ALTER TABLE kdeaco_events ADD COLUMN source_id varchar(190) NULL',
            'ALTER TABLE kdeaco_events ADD COLUMN synced_at datetime NULL',
        ] as $sql) {
            try {
                $conn->executeStatement($sql);
            } catch (\Throwable) {
                // column already exists
            }
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('kdeaco_events')) {
            return;
        }
        $table = $schema->getTable('kdeaco_events');
        if ($table->hasIndex('uniq_kdeaco_events_source')) {
            $table->dropIndex('uniq_kdeaco_events_source');
        }
        if ($table->hasIndex('idx_kdeaco_events_source')) {
            $table->dropIndex('idx_kdeaco_events_source');
        }
        foreach (['synced_at', 'source_id', 'source'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }
    }
};
