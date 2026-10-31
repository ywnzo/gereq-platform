<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('product_reviews')) {
            return;
        }

        $table = $schema->getTable('product_reviews');
        if ($table->hasColumn('is_verified_purchase')) {
            return;
        }

        $table->addColumn('is_verified_purchase', Types::BOOLEAN)
            ->setNotnull(true)
            ->setDefault(false);
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('product_reviews')) {
            return;
        }

        $table = $schema->getTable('product_reviews');
        if ($table->hasColumn('is_verified_purchase')) {
            $table->dropColumn('is_verified_purchase');
        }
    }
};
