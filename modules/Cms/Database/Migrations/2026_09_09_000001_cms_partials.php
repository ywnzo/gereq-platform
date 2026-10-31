<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool creates cms_partials table from CmsPartial entity.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('cms_partials')) {
            $schema->dropTable('cms_partials');
        }
    }
};
