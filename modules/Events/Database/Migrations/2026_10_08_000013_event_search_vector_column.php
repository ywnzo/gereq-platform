<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;

/**
 * Keep kdeaco_events.search_vector durable: SchemaTool used to DROP the unmapped
 * column while the BEFORE INSERT trigger remained, breaking public submit.
 */
return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kdeaco_events')) {
            return;
        }
        $table = $schema->getTable('kdeaco_events');
        if (!$table->hasColumn('search_vector')) {
            $table->addColumn('search_vector', 'text', [
                'notnull' => false,
                'columnDefinition' => 'tsvector DEFAULT NULL',
            ]);
        }
    }

    public function afterUp(Connection $conn): void
    {
        $platform = $conn->getDatabasePlatform();
        if (!$platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
            return;
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
            // FTS optional
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('kdeaco_events')) {
            return;
        }
        $table = $schema->getTable('kdeaco_events');
        if ($table->hasColumn('search_vector')) {
            $table->dropColumn('search_vector');
        }
    }
};
