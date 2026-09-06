<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulku carservice_branches vytváří SchemaTool z entity ServiceBranch.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('carservice_branches')) {
            $schema->dropTable('carservice_branches');
        }
    }
};
