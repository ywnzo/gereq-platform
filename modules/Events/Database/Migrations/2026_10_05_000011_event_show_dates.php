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
        if (!$table->hasColumn('show_starts_at')) {
            $table->addColumn('show_starts_at', Types::BOOLEAN)
                ->setNotnull(true)
                ->setDefault(true);
        }
        if (!$table->hasColumn('show_ends_at')) {
            $table->addColumn('show_ends_at', Types::BOOLEAN)
                ->setNotnull(true)
                ->setDefault(true);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('kdeaco_events')) {
            return;
        }
        $table = $schema->getTable('kdeaco_events');
        if ($table->hasColumn('show_starts_at')) {
            $table->dropColumn('show_starts_at');
        }
        if ($table->hasColumn('show_ends_at')) {
            $table->dropColumn('show_ends_at');
        }
    }
};
