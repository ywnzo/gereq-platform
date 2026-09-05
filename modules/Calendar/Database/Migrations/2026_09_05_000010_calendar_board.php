<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/**
 * Rozšíření calendar_events + tabulka calendar_connections.
 * SchemaTool doplní zbytek; zde DEFAULT hodnoty pro SQLite.
 */
return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('calendar_events')) {
            $table = $schema->getTable('calendar_events');
            if (!$table->hasColumn('color')) {
                $table->addColumn('color', Types::STRING)->setLength(16)->setNotnull(true)->setDefault('blue');
            }
            if (!$table->hasColumn('status')) {
                $table->addColumn('status', Types::STRING)->setLength(16)->setNotnull(true)->setDefault('confirmed');
            }
            if (!$table->hasColumn('timezone')) {
                $table->addColumn('timezone', Types::STRING)->setLength(64)->setNotnull(false)->setDefault('Europe/Prague');
            }
            if (!$table->hasColumn('location')) {
                $table->addColumn('location', Types::STRING)->setLength(255)->setNotnull(false);
            }
            if (!$table->hasColumn('rrule')) {
                $table->addColumn('rrule', Types::STRING)->setLength(255)->setNotnull(false);
            }
            if (!$table->hasColumn('external_provider')) {
                $table->addColumn('external_provider', Types::STRING)->setLength(32)->setNotnull(false);
            }
            if (!$table->hasColumn('external_id')) {
                $table->addColumn('external_id', Types::STRING)->setLength(190)->setNotnull(false);
            }
            if (!$table->hasColumn('external_etag')) {
                $table->addColumn('external_etag', Types::STRING)->setLength(190)->setNotnull(false);
            }
            if (!$table->hasColumn('connection_id')) {
                $table->addColumn('connection_id', Types::STRING)->setLength(36)->setNotnull(false);
            }
            if (!$table->hasColumn('created_by')) {
                $table->addColumn('created_by', Types::STRING)->setLength(36)->setNotnull(false);
            }
            if (!$table->hasIndex('idx_calendar_external')) {
                // ok
            } elseif (method_exists($table, 'addIndex')) {
                $table->addIndex(['external_provider', 'external_id'], 'idx_calendar_external');
            }
        }

        if (!$schema->hasTable('calendar_connections')) {
            $t = $schema->createTable('calendar_connections');
            $t->addColumn('id', Types::STRING)->setLength(36)->setNotnull(true);
            $t->addColumn('provider', Types::STRING)->setLength(32)->setNotnull(true);
            $t->addColumn('account_email', Types::STRING)->setLength(190)->setNotnull(true);
            $t->addColumn('display_name', Types::STRING)->setLength(190)->setNotnull(false);
            $t->addColumn('status', Types::STRING)->setLength(16)->setNotnull(true)->setDefault('pending');
            $t->addColumn('token_cipher', Types::TEXT)->setNotnull(false);
            $t->addColumn('caldav_url', Types::STRING)->setLength(500)->setNotnull(false);
            $t->addColumn('calendar_id', Types::STRING)->setLength(255)->setNotnull(false);
            $t->addColumn('sync_token', Types::STRING)->setLength(255)->setNotnull(false);
            $t->addColumn('last_synced_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
            $t->addColumn('last_error', Types::TEXT)->setNotnull(false);
            $t->addColumn('user_id', Types::STRING)->setLength(36)->setNotnull(false);
            $t->addColumn('color', Types::STRING)->setLength(16)->setNotnull(true)->setDefault('blue');
            $t->addColumn('sync_enabled', Types::BOOLEAN)->setNotnull(true)->setDefault(true);
            $t->addColumn('created_at', Types::DATETIME_IMMUTABLE)->setNotnull(true);
            $t->addColumn('updated_at', Types::DATETIME_IMMUTABLE)->setNotnull(true);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['provider', 'account_email'], 'uniq_calendar_conn_provider_account');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('calendar_connections')) {
            $schema->dropTable('calendar_connections');
        }
    }
};
