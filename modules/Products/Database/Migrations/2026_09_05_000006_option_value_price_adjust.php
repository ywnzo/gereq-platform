<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('product_option_values')) {
            return;
        }
        $table = $schema->getTable('product_option_values');
        if (!$table->hasColumn('price_adjust_kind')) {
            $table->addColumn('price_adjust_kind', Types::STRING)->setLength(16)->setDefault('none');
        }
        if (!$table->hasColumn('price_adjust_value')) {
            $table->addColumn('price_adjust_value', Types::DECIMAL)->setPrecision(14)->setScale(2)->setDefault('0.00');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('product_option_values')) {
            return;
        }
        $table = $schema->getTable('product_option_values');
        if ($table->hasColumn('price_adjust_value')) {
            $table->dropColumn('price_adjust_value');
        }
        if ($table->hasColumn('price_adjust_kind')) {
            $table->dropColumn('price_adjust_kind');
        }
    }
};
