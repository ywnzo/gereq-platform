<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('product_images')) {
            return;
        }

        $table = $schema->createTable('product_images');
        $table->addColumn('id', Types::STRING)->setLength(36);
        $table->addColumn('product_id', Types::STRING)->setLength(36);
        $table->addColumn('media_asset_id', Types::STRING)->setLength(36);
        $table->addColumn('sort_order', Types::INTEGER)->setDefault(0);
        $table->addColumn('is_primary', Types::BOOLEAN)->setDefault(false);
        $table->addColumn('alt', Types::STRING)->setLength(255)->setNotnull(false);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['product_id'], 'idx_product_images_product');
        $table->addIndex(['media_asset_id'], 'idx_product_images_media');
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('product_images')) {
            $schema->dropTable('product_images');
        }
    }
};
