<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulky webhooks a webhook_deliveries vytváří Doctrine SchemaTool z entit.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('webhook_deliveries')) {
            $schema->dropTable('webhook_deliveries');
        }
        if ($schema->hasTable('webhooks')) {
            $schema->dropTable('webhooks');
        }
    }
};
