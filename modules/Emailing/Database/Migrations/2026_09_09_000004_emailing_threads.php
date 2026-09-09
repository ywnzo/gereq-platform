<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('emailing_messages')) {
            $table = $schema->getTable('emailing_messages');
            if (!$table->hasColumn('in_reply_to')) {
                $table->addColumn('in_reply_to', Types::STRING)->setLength(255)->setNotnull(false);
            }
            if (!$table->hasColumn('references_raw')) {
                $table->addColumn('references_raw', Types::TEXT)->setNotnull(false);
            }
            if (!$table->hasColumn('thread_id')) {
                $table->addColumn('thread_id', Types::STRING)->setLength(80)->setNotnull(false);
            }
            if (!$table->hasColumn('thread_subject')) {
                $table->addColumn('thread_subject', Types::STRING)->setLength(500)->setNotnull(false);
            }
            if (!$table->hasIndex('idx_emailing_messages_thread')) {
                $table->addIndex(['account_id', 'thread_id'], 'idx_emailing_messages_thread');
            }
            if (!$table->hasIndex('idx_emailing_messages_msgid')) {
                $table->addIndex(['account_id', 'message_id'], 'idx_emailing_messages_msgid');
            }
        }

        if ($schema->hasTable('emailing_outbox')) {
            $table = $schema->getTable('emailing_outbox');
            if (!$table->hasColumn('in_reply_to')) {
                $table->addColumn('in_reply_to', Types::STRING)->setLength(255)->setNotnull(false);
            }
            if (!$table->hasColumn('references_raw')) {
                $table->addColumn('references_raw', Types::TEXT)->setNotnull(false);
            }
        }
    }

    public function down(Schema $schema): void
    {
    }
};
