<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool: partscatalog_api_cache
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('partscatalog_api_cache')) {
            $schema->dropTable('partscatalog_api_cache');
        }
    }
};
