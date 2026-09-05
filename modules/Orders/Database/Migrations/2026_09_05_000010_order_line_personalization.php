<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('order_lines')) {
            return;
        }
        $table = $schema->getTable('order_lines');
        if (!$table->hasColumn('item_note')) {
            $table->addColumn('item_note', Types::TEXT)->setNotnull(false);
        }
        if (!$table->hasColumn('meta_json')) {
            $table->addColumn('meta_json', Types::TEXT)->setNotnull(false);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('order_lines')) {
            return;
        }
        $table = $schema->getTable('order_lines');
        if ($table->hasColumn('item_note')) {
            $table->dropColumn('item_note');
        }
        if ($table->hasColumn('meta_json')) {
            $table->dropColumn('meta_json');
        }
    }
};
