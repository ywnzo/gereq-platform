<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('customers')) {
            return;
        }

        $table = $schema->getTable('customers');
        if (!$table->hasColumn('avatar_file_id')) {
            $table->addColumn('avatar_file_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('customers')) {
            return;
        }

        $table = $schema->getTable('customers');
        if ($table->hasColumn('avatar_file_id')) {
            $table->dropColumn('avatar_file_id');
        }
    }
};
