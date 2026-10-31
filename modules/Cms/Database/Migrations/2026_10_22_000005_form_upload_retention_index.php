<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        $schema->getTable('cms_form_uploads')->addIndex(['created_at'], 'idx_cms_form_uploads_created');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('cms_form_uploads')->dropIndex('idx_cms_form_uploads_created');
    }
};
