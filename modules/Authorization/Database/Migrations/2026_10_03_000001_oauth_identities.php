<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kdeaco_oauth_identities') && !$schema->hasTable('auth_oauth_identities')) {
            $schema->renameTable('kdeaco_oauth_identities', 'auth_oauth_identities');
        }
    }

    public function afterUp(Connection $conn): void
    {
        $sm = $conn->createSchemaManager();
        $tables = $sm->listTableNames();
        $hasLegacy = in_array('kdeaco_oauth_identities', $tables, true);
        $hasAuth = in_array('auth_oauth_identities', $tables, true);
        if ($hasLegacy && $hasAuth) {
            $conn->executeStatement(
                'INSERT INTO auth_oauth_identities (id, provider, provider_user_id, customer_id, email, created_at)
                 SELECT id, provider, provider_user_id, customer_id, email, created_at
                 FROM kdeaco_oauth_identities
                 WHERE id NOT IN (SELECT id FROM auth_oauth_identities)'
            );
            $conn->executeStatement('DROP TABLE kdeaco_oauth_identities');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('auth_oauth_identities') && !$schema->hasTable('kdeaco_oauth_identities')) {
            $schema->renameTable('auth_oauth_identities', 'kdeaco_oauth_identities');
        }
    }
};
