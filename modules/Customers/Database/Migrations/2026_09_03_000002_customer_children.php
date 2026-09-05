<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulky child entit vytváří Doctrine SchemaTool z Domain/* entit.
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'customer_tag_links',
            'customer_relations',
            'customer_notes',
            'customer_contacts',
            'customer_addresses',
            'customer_tags',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
};
