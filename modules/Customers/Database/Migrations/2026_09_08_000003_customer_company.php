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
        if (!$table->hasColumn('is_company')) {
            $table->addColumn('is_company', Types::BOOLEAN, ['default' => false]);
        }
        if (!$table->hasColumn('ico')) {
            $table->addColumn('ico', Types::STRING, ['length' => 12, 'notnull' => false]);
        }
        if (!$table->hasColumn('dic')) {
            $table->addColumn('dic', Types::STRING, ['length' => 20, 'notnull' => false]);
        }
        if (!$table->hasColumn('company_address')) {
            $table->addColumn('company_address', Types::STRING, ['length' => 255, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('customers')) {
            return;
        }

        $table = $schema->getTable('customers');
        foreach (['company_address', 'dic', 'ico', 'is_company'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }
    }
};
