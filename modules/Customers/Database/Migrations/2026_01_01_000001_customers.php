<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulku customers vytváří Doctrine SchemaTool z entity.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('customers')) {
            $schema->dropTable('customers');
        }
    }
};
