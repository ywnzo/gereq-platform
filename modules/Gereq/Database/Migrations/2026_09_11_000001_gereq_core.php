<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tables are created by Doctrine SchemaTool from Domain entities.
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'gereq_ticket_messages',
            'gereq_tickets',
            'gereq_ticket_sites',
            'gereq_security_events',
            'gereq_security_sites',
            'gereq_sla_policies',
            'gereq_canned_replies',
            'gereq_support_tags',
            'gereq_kb_articles',
            'gereq_automations',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
};
