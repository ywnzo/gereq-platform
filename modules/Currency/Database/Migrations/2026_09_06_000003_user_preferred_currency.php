<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool: currency_user_prefs (UserCurrencyPreference)
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('currency_user_prefs')) {
            $schema->dropTable('currency_user_prefs');
        }
    }
};
