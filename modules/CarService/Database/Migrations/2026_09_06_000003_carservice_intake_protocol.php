<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool: carservice_orders.intake_protocol_json
    }

    public function down(Schema $schema): void
    {
    }
};
