<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;

/**
 * Listing / search performance for kdeaco_events:
 * - composite B-trees for public filters + sort
 * - pg_trgm for substring ILIKE
 * - tsvector + GIN for full-text search
 */
return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kdeaco_events')) {
            return;
        }
        $table = $schema->getTable('kdeaco_events');
        if (!$table->hasIndex('idx_kdeaco_events_status_starts')) {
            $table->addIndex(['status', 'starts_at'], 'idx_kdeaco_events_status_starts');
        }
        if (!$table->hasIndex('idx_kdeaco_events_status_region_starts')) {
            $table->addIndex(['status', 'region', 'starts_at'], 'idx_kdeaco_events_status_region_starts');
        }
        if (!$table->hasIndex('idx_kdeaco_events_status_category')) {
            $table->addIndex(['status', 'category'], 'idx_kdeaco_events_status_category');
        }
        if (!$table->hasIndex('idx_kdeaco_events_spotlight_region')) {
            $table->addIndex(['spotlight', 'region'], 'idx_kdeaco_events_spotlight_region');
        }
        if (!$table->hasIndex('idx_kdeaco_events_ends')) {
            $table->addIndex(['ends_at'], 'idx_kdeaco_events_ends');
        }
    }

    public function afterUp(Connection $conn): void
    {
        $platform = $conn->getDatabasePlatform();
        if (!$platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            return;
        }

        foreach ([
            'CREATE INDEX IF NOT EXISTS idx_kdeaco_events_status_starts ON kdeaco_events (status, starts_at)',
            'CREATE INDEX IF NOT EXISTS idx_kdeaco_events_status_region_starts ON kdeaco_events (status, region, starts_at)',
            'CREATE INDEX IF NOT EXISTS idx_kdeaco_events_status_category ON kdeaco_events (status, category)',
            'CREATE INDEX IF NOT EXISTS idx_kdeaco_events_spotlight_region ON kdeaco_events (spotlight, region)',
            'CREATE INDEX IF NOT EXISTS idx_kdeaco_events_ends ON kdeaco_events (ends_at)',
            "CREATE INDEX IF NOT EXISTS idx_kdeaco_events_public_live ON kdeaco_events (starts_at) WHERE status = 'published'",
        ] as $sql) {
            $conn->executeStatement($sql);
        }

        try {
            $conn->executeStatement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\Throwable) {
            // extension may require superuser — trigram indexes skipped below if missing
        }

        try {
            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_kdeaco_events_title_trgm ON kdeaco_events USING gin (lower(title) gin_trgm_ops)',
            );
            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_kdeaco_events_place_trgm ON kdeaco_events USING gin (lower(place) gin_trgm_ops)',
            );
            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_kdeaco_events_description_trgm ON kdeaco_events USING gin (lower(description) gin_trgm_ops)',
            );
        } catch (\Throwable) {
            // pg_trgm unavailable
        }

        try {
            $conn->executeStatement(
                'ALTER TABLE kdeaco_events ADD COLUMN IF NOT EXISTS search_vector tsvector',
            );
            $conn->executeStatement(
                "UPDATE kdeaco_events SET search_vector =
                    setweight(to_tsvector('simple', coalesce(title, '')), 'A')
                    || setweight(to_tsvector('simple', coalesce(place, '')), 'A')
                    || setweight(to_tsvector('simple', coalesce(region, '')), 'B')
                    || setweight(to_tsvector('simple', coalesce(category, '')), 'B')
                    || setweight(to_tsvector('simple', coalesce(description, '')), 'C')
                    || setweight(to_tsvector('simple', coalesce(organizer, '')), 'C')
                 WHERE search_vector IS NULL",
            );
            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_kdeaco_events_search_vector ON kdeaco_events USING gin (search_vector)',
            );
            $conn->executeStatement(
                'CREATE OR REPLACE FUNCTION kdeaco_events_search_vector_update() RETURNS trigger AS $$
                BEGIN
                    NEW.search_vector :=
                        setweight(to_tsvector(\'simple\', coalesce(NEW.title, \'\')), \'A\')
                        || setweight(to_tsvector(\'simple\', coalesce(NEW.place, \'\')), \'A\')
                        || setweight(to_tsvector(\'simple\', coalesce(NEW.region, \'\')), \'B\')
                        || setweight(to_tsvector(\'simple\', coalesce(NEW.category, \'\')), \'B\')
                        || setweight(to_tsvector(\'simple\', coalesce(NEW.description, \'\')), \'C\')
                        || setweight(to_tsvector(\'simple\', coalesce(NEW.organizer, \'\')), \'C\');
                    RETURN NEW;
                END
                $$ LANGUAGE plpgsql',
            );
            $conn->executeStatement('DROP TRIGGER IF EXISTS trg_kdeaco_events_search_vector ON kdeaco_events');
            $conn->executeStatement(
                'CREATE TRIGGER trg_kdeaco_events_search_vector
                 BEFORE INSERT OR UPDATE OF title, place, region, category, description, organizer
                 ON kdeaco_events
                 FOR EACH ROW EXECUTE PROCEDURE kdeaco_events_search_vector_update()',
            );
        } catch (\Throwable) {
            // FTS optional — LIKE/trigram still works
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('kdeaco_events')) {
            return;
        }
        $table = $schema->getTable('kdeaco_events');
        foreach ([
            'idx_kdeaco_events_status_starts',
            'idx_kdeaco_events_status_region_starts',
            'idx_kdeaco_events_status_category',
            'idx_kdeaco_events_spotlight_region',
            'idx_kdeaco_events_ends',
        ] as $index) {
            if ($table->hasIndex($index)) {
                $table->dropIndex($index);
            }
        }
    }
};
