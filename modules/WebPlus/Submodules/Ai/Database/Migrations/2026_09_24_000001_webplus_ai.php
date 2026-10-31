<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool creates webplus_ai_tools from AgentTool entity.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('webplus_ai_tools')) {
            $schema->dropTable('webplus_ai_tools');
        }
    }
};
