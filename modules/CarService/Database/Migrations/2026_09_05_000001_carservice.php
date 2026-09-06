<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulky carservice_* vytváří SchemaTool z entit.
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'carservice_branches',
            'carservice_costs',
            'carservice_order_links',
            'carservice_team_switches',
            'carservice_jobs',
            'carservice_orders',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
};
