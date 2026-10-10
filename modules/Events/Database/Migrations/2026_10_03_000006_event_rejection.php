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
        if (!$table->hasColumn('rejection_reason')) {
            $table->addColumn('rejection_reason', Types::TEXT)->setNotnull(false);
        }
        if (!$table->hasColumn('rejected_at')) {
            $table->addColumn('rejected_at', Types::DATETIME_IMMUTABLE)->setNotnull(false);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('kdeaco_events')) {
            return;
        }
        $table = $schema->getTable('kdeaco_events');
        if ($table->hasColumn('rejected_at')) {
            $table->dropColumn('rejected_at');
        }
        if ($table->hasColumn('rejection_reason')) {
            $table->dropColumn('rejection_reason');
        }
    }
};
