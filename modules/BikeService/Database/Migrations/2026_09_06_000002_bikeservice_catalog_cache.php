<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool: bikeservice_catalog_cache
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('bikeservice_catalog_cache')) {
            $schema->dropTable('bikeservice_catalog_cache');
        }
    }
};
