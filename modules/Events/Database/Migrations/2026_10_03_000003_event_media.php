<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kdeaco_events')) {
            return;
        }
        $table = $schema->getTable('kdeaco_events');
        if (!$table->hasColumn('media_asset_id')) {
            $table->addColumn('media_asset_id', Types::STRING)->setLength(36)->setNotnull(false);
            $table->addIndex(['media_asset_id'], 'idx_kdeaco_events_media');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('kdeaco_events')) {
            return;
        }
        $table = $schema->getTable('kdeaco_events');
        if ($table->hasIndex('idx_kdeaco_events_media')) {
            $table->dropIndex('idx_kdeaco_events_media');
        }
        if ($table->hasColumn('media_asset_id')) {
            $table->dropColumn('media_asset_id');
        }
    }
};
