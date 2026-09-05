<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool: fleet_vehicle_brands, fleet_vehicle_models, vignette_until.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('fleet_vehicle_models')) {
            $schema->dropTable('fleet_vehicle_models');
        }
        if ($schema->hasTable('fleet_vehicle_brands')) {
            $schema->dropTable('fleet_vehicle_brands');
        }
    }
};
