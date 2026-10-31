<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('site_inquiries')) {
            // Fresh installs obtain these columns from Doctrine entity metadata.
            return;
        }
        $table = $schema->getTable('site_inquiries');
        $table->addColumn('notification_status', Types::STRING, ['length' => 20, 'default' => 'not_attempted']);
        $table->addColumn('notification_attempts', Types::INTEGER, ['default' => 0]);
        foreach (['notification_next_at', 'notification_lease_until', 'notification_sent_at'] as $name) {
            $table->addColumn($name, Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        }
        $table->addColumn('notification_lease_token', Types::STRING, ['length' => 36, 'notnull' => false]);
        $table->addIndex(['notification_status', 'notification_next_at'], 'idx_site_inquiries_notification_due');
    }

    /** Rollback discards pending delivery state; it never sends historical inquiries. */
    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('site_inquiries')) {
            return;
        }
        $table = $schema->getTable('site_inquiries');
        $table->dropIndex('idx_site_inquiries_notification_due');
        foreach (['notification_status', 'notification_attempts', 'notification_next_at', 'notification_lease_until', 'notification_sent_at', 'notification_lease_token'] as $name) {
            $table->dropColumn($name);
        }
    }
};
