<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Alter v afterUp — DBAL Schema nemá changeColumn v této verzi.
    }

    public function afterUp(Connection $connection): void
    {
        if (!$connection->createSchemaManager()->tablesExist(['emailing_messages'])) {
            return;
        }
        try {
            $connection->executeStatement(
                'ALTER TABLE emailing_messages ALTER COLUMN thread_id TYPE VARCHAR(120)'
            );
        } catch (\Throwable) {
        }
        try {
            $connection->executeStatement('UPDATE emailing_messages SET thread_id = NULL');
        } catch (\Throwable) {
        }
    }

    public function down(Schema $schema): void
    {
    }
};
