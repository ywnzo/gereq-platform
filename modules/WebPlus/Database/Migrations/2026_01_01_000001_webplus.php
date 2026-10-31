<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Schema from Doctrine entity attributes (WebMeta).
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('webplus_metas')) {
            $schema->dropTable('webplus_metas');
        }
    }
};
