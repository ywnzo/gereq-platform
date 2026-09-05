<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulky currencies a exchange_rates vytváří Doctrine SchemaTool z entit.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('exchange_rates')) {
            $schema->dropTable('exchange_rates');
        }
        if ($schema->hasTable('currencies')) {
            $schema->dropTable('currencies');
        }
    }
};
