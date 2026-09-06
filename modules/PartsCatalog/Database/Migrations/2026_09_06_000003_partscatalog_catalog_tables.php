<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool: partscatalog_brands, partscatalog_models, partscatalog_vehicles,
        // partscatalog_categories, partscatalog_parts, partscatalog_vehicle_parts
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'partscatalog_vehicle_parts',
            'partscatalog_parts',
            'partscatalog_categories',
            'partscatalog_vehicles',
            'partscatalog_models',
            'partscatalog_brands',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
};
