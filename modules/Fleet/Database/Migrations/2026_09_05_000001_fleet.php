<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulky fleet_vehicles a fleet_vehicle_status_logs vytváří SchemaTool z entit.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('fleet_vehicle_status_logs')) {
            $schema->dropTable('fleet_vehicle_status_logs');
        }
        if ($schema->hasTable('fleet_vehicles')) {
            $schema->dropTable('fleet_vehicles');
        }
    }
};
