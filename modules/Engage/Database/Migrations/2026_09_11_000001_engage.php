<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tables are created by Doctrine SchemaTool from Domain entities.
    }

    public function down(Schema $schema): void
    {
        foreach (['engage_spots', 'engage_campaigns'] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
};
