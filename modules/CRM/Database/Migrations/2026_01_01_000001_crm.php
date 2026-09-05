<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulky crm_deals a crm_activities vytváří Doctrine SchemaTool z entit.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('crm_activities')) {
            $schema->dropTable('crm_activities');
        }
        if ($schema->hasTable('crm_deals')) {
            $schema->dropTable('crm_deals');
        }
    }
};
