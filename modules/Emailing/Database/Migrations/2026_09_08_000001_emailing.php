<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulky emailing_* vytváří Doctrine SchemaTool z entit.
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'emailing_outbox',
            'emailing_attachments',
            'emailing_bodies',
            'emailing_messages',
            'emailing_folders',
            'emailing_accounts',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
};
