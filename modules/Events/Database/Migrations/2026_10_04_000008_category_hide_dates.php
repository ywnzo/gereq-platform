<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kdeaco_event_categories')) {
            return;
        }
        $table = $schema->getTable('kdeaco_event_categories');
        if ($table->hasColumn('hide_dates')) {
            return;
        }
        $table->addColumn('hide_dates', Types::BOOLEAN)
            ->setNotnull(true)
            ->setDefault(false);
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('kdeaco_event_categories')) {
            return;
        }
        $table = $schema->getTable('kdeaco_event_categories');
        if ($table->hasColumn('hide_dates')) {
            $table->dropColumn('hide_dates');
        }
    }
};
