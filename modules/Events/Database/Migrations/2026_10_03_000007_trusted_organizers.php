<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kdeaco_trusted_organizers')) {
            return;
        }
        $table = $schema->createTable('kdeaco_trusted_organizers');
        $table->addColumn('id', Types::STRING)->setLength(36);
        $table->addColumn('customer_id', Types::STRING)->setLength(36);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['customer_id'], 'uniq_kdeaco_trusted_organizer');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kdeaco_trusted_organizers')) {
            $schema->dropTable('kdeaco_trusted_organizers');
        }
    }
};
