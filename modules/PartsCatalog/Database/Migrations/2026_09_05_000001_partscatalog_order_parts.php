<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool: partscatalog_order_parts
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('partscatalog_order_parts')) {
            $schema->dropTable('partscatalog_order_parts');
        }
    }
};
