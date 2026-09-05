<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulky vitality_endpoint_stats a vitality_slow_requests vytváří SchemaTool z entit.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('vitality_slow_requests')) {
            $schema->dropTable('vitality_slow_requests');
        }
        if ($schema->hasTable('vitality_endpoint_stats')) {
            $schema->dropTable('vitality_endpoint_stats');
        }
    }
};
