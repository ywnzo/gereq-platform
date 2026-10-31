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
        if ($table->hasColumn('helpful_count')) {
            return;
        }

        $table->addColumn('helpful_count', Types::INTEGER)
            ->setNotnull(true)
            ->setDefault(0);
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('product_reviews')) {
            return;
        }

        $table = $schema->getTable('product_reviews');
        if ($table->hasColumn('helpful_count')) {
            $table->dropColumn('helpful_count');
        }
    }
};
