<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('portal_providers')) {
            return;
        }
        $t = $schema->getTable('portal_providers');
        if (!$t->hasColumn('openapi_url')) {
            $t->addColumn('openapi_url', Types::STRING)->setLength(500)->setNotnull(false);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('portal_providers')) {
            return;
        }
        $t = $schema->getTable('portal_providers');
        if ($t->hasColumn('openapi_url')) {
            $t->dropColumn('openapi_url');
        }
    }
};
