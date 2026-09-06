<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulku carservice_user_branch_prefs vytváří SchemaTool z entity UserBranchPreference.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('carservice_user_branch_prefs')) {
            $schema->dropTable('carservice_user_branch_prefs');
        }
    }
};
