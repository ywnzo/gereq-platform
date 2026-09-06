<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulky bikeservice_* vytváří SchemaTool z entit.
    }

    public function down(Schema $schema): void
    {
        foreach (['bikeservice_jobs', 'bikeservice_orders', 'bikeservice_bikes', 'bikeservice_catalog_cache'] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
};
