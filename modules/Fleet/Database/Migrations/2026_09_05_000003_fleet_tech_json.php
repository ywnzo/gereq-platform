<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool: fleet_vehicles.tech_json (ORV tech extras).
    }

    public function down(Schema $schema): void
    {
    }
};
