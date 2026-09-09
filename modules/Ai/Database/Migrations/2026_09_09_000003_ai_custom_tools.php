<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // ai_custom_tools — Doctrine SchemaTool from Domain\AiCustomTool.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('ai_custom_tools')) {
            $schema->dropTable('ai_custom_tools');
        }
    }
};
