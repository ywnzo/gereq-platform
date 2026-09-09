<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // ai_agent_peers + ai_feedback — Doctrine SchemaTool from Domain entities.
    }

    public function down(Schema $schema): void
    {
        foreach (['ai_feedback', 'ai_agent_peers'] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
};
