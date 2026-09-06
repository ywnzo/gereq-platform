<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool: media_folders + media_assets.folder_id
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('media_assets') && $schema->getTable('media_assets')->hasColumn('folder_id')) {
            $schema->getTable('media_assets')->dropColumn('folder_id');
        }
        if ($schema->hasTable('media_folders')) {
            $schema->dropTable('media_folders');
        }
    }
};
