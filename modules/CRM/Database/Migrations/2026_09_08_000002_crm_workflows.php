<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // crm_workflows / crm_workflow_runs — Doctrine SchemaTool
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('crm_workflow_runs')) {
            $schema->dropTable('crm_workflow_runs');
        }
        if ($schema->hasTable('crm_workflows')) {
            $schema->dropTable('crm_workflows');
        }
    }
};
