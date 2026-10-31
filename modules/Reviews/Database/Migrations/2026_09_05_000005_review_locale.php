<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('product_reviews')) {
            return;
        }

        $table = $schema->getTable('product_reviews');
        if ($table->hasColumn('locale')) {
            return;
        }

        $table->addColumn('locale', Types::STRING)
            ->setLength(16)
            ->setNotnull(true)
            ->setDefault('cs');
    }

    public function afterUp(Connection $connection): void
    {
        if (!$connection->createSchemaManager()->tablesExist(['product_reviews'])) {
            return;
        }

        $columns = $connection->createSchemaManager()->listTableColumns('product_reviews');
        if (!isset($columns['locale'])) {
            return;
        }

        $connection->executeStatement(
            "UPDATE product_reviews SET locale = 'cs' WHERE locale IS NULL OR locale = ''"
        );
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('product_reviews')) {
            return;
        }

        $table = $schema->getTable('product_reviews');
        if ($table->hasColumn('locale')) {
            $table->dropColumn('locale');
        }
    }
};
