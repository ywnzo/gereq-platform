<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('emailing_attachments')) {
            return;
        }

        $table = $schema->getTable('emailing_attachments');
        if (!$table->hasColumn('content_id')) {
            $table->addColumn('content_id', Types::STRING)
                ->setLength(190)
                ->setNotnull(false);
        }
        if (!$table->hasColumn('is_inline')) {
            $table->addColumn('is_inline', Types::BOOLEAN)
                ->setNotnull(true)
                ->setDefault(false);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('emailing_attachments')) {
            return;
        }
        $table = $schema->getTable('emailing_attachments');
        foreach (['is_inline', 'content_id'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }
    }
};
