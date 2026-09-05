<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulku media_links vytváří SchemaTool z entity MediaLink.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('media_links')) {
            $schema->dropTable('media_links');
        }
    }
};
