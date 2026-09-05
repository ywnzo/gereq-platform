<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('product_review_replies')) {
            return;
        }

        $table = $schema->createTable('product_review_replies');
        $table->addColumn('id', Types::STRING)->setLength(36);
        $table->addColumn('review_id', Types::STRING)->setLength(36);
        $table->addColumn('author_name', Types::STRING)->setLength(190);
        $table->addColumn('body', Types::TEXT);
        $table->addColumn('is_official', Types::BOOLEAN)->setNotnull(true)->setDefault(true);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['review_id'], 'idx_product_review_replies_review');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('product_review_replies')) {
            $schema->dropTable('product_review_replies');
        }
    }
};
