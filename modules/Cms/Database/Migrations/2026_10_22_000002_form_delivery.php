<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('cms_form_attempts') && !$schema->getTable('cms_form_attempts')->hasColumn('delivery_status')) {
            $schema->getTable('cms_form_attempts')->addColumn('delivery_status', Types::STRING, ['length' => 32, 'default' => 'unknown']);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('cms_form_attempts') && $schema->getTable('cms_form_attempts')->hasColumn('delivery_status')) {
            $schema->getTable('cms_form_attempts')->dropColumn('delivery_status');
        }
    }
};
