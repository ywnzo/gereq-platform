<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('site_inquiries')) {
            return;
        }

        $table = $schema->getTable('site_inquiries');
        if (!$table->hasColumn('status')) {
            $table->addColumn('status', Types::STRING, [
                'length' => 20,
                'default' => 'open',
                'notnull' => true,
            ]);
        }
        if (!$table->hasColumn('resolved_at')) {
            $table->addColumn('resolved_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        }
        if (!$table->hasIndex('idx_site_inquiries_status')) {
            $table->addIndex(['status'], 'idx_site_inquiries_status');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('site_inquiries')) {
            return;
        }

        $table = $schema->getTable('site_inquiries');
        if ($table->hasIndex('idx_site_inquiries_status')) {
            $table->dropIndex('idx_site_inquiries_status');
        }
        if ($table->hasColumn('resolved_at')) {
            $table->dropColumn('resolved_at');
        }
        if ($table->hasColumn('status')) {
            $table->dropColumn('status');
        }
    }
};
