<?php

/** Analytics: tracked sites, pseudonymous visitors, sessions, pageviews, events, rollups, consent. */

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    /**
     * Tables, indexes and unique constraints of this module are declared by the Doctrine mapping
     * in `modules/Analytics/Domain/` and materialised by `Gereq\Database\Migrator::syncSchema()`
     * (SchemaTool schema sync over every enabled module). This migration therefore only records
     * the version; it creates nothing itself.
     *
     * Every index of the frozen contract is expressible in the mapping — no raw DDL is needed:
     *  - `uniq_analytics_sites_key`
     *  - `uniq_analytics_visitors_site_hash`, `idx_analytics_visitors_site_last_seen`
     *  - `idx_analytics_sessions_site_started`, `idx_analytics_sessions_site_activity`,
     *    `idx_analytics_sessions_visitor`
     *  - `idx_analytics_pageviews_site_occurred`, `idx_analytics_pageviews_session`,
     *    `idx_analytics_pageviews_site_path`, `idx_analytics_pageviews_visitor`
     *  - `idx_analytics_events_site_occurred`, `idx_analytics_events_site_name_occurred`
     *  - `uniq_analytics_daily_stats_site_day`, `idx_analytics_daily_stats_day`
     *  - `uniq_analytics_daily_dimensions_bucket`
     *  - `idx_analytics_consent_events_site_occurred`
     *
     * There is no backfill: the module starts with empty tables and collects its own data.
     */
    public function up(Schema $schema): void
    {
        // Intentionally empty, see the class docblock.
    }

    /**
     * Drop every table owned by this module, children first.
     *
     * There are no foreign keys (all cross-table references are plain string id columns), so the
     * order is a readability aid. Rollback is destructive: all collected analytics data of all
     * sites is lost, and there is no way to reconstruct it, because raw beacons are not retained
     * anywhere else. `Gereq\Database\Migrator::rollbackModule()` additionally drops the mapped
     * tables through SchemaTool, so `hasTable()` guards are required.
     */
    public function down(Schema $schema): void
    {
        foreach ([
            'analytics_consent_events',
            'analytics_daily_dimensions',
            'analytics_daily_stats',
            'analytics_events',
            'analytics_pageviews',
            'analytics_sessions',
            'analytics_visitors',
            'analytics_sites',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
};
