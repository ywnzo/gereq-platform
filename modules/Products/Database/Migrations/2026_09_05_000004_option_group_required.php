<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('product_option_groups')) {
            return;
        }
        $table = $schema->getTable('product_option_groups');
        if (!$table->hasColumn('is_required')) {
            $table->addColumn('is_required', Types::BOOLEAN)
                ->setDefault(true)
                ->setNotnull(true);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('product_option_groups')) {
            return;
        }
        $table = $schema->getTable('product_option_groups');
        if ($table->hasColumn('is_required')) {
            $table->dropColumn('is_required');
        }
    }
};
