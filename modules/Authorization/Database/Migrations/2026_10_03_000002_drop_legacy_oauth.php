<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
    }

    public function afterUp(Connection $conn): void
    {
        $sm = $conn->createSchemaManager();
        $tables = $sm->listTableNames();
        if (!in_array('kdeaco_oauth_identities', $tables, true)) {
            return;
        }
        if (in_array('auth_oauth_identities', $tables, true)) {
            $conn->executeStatement(
                'INSERT INTO auth_oauth_identities (id, provider, provider_user_id, customer_id, email, created_at)
                 SELECT id, provider, provider_user_id, customer_id, email, created_at
                 FROM kdeaco_oauth_identities
                 WHERE id NOT IN (SELECT id FROM auth_oauth_identities)'
            );
        }
        $conn->executeStatement('DROP TABLE IF EXISTS kdeaco_oauth_identities');
    }

    public function down(Schema $schema): void
    {
    }
};
