<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if ($schema->hasTable('products')) {
            $table = $schema->getTable('products');
            if (!$table->hasColumn('customer_note_mode')) {
                $table->addColumn('customer_note_mode', Types::STRING)
                    ->setLength(16)
                    ->setDefault('off')
                    ->setNotnull(true);
            }
        }

        if (!$schema->hasTable('product_user_image_rules')) {
            $table = $schema->createTable('product_user_image_rules');
            $table->addColumn('id', Types::STRING)->setLength(36);
            $table->addColumn('product_id', Types::STRING)->setLength(36);
            $table->addColumn('max_images', Types::INTEGER)->setDefault(1);
            $table->addColumn('crop_required', Types::BOOLEAN)->setDefault(false);
            $table->addColumn('is_active', Types::BOOLEAN)->setDefault(true);
            $table->addColumn('crop_config_json', Types::TEXT)->setNotnull(false);
            $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['product_id'], 'uniq_product_user_image_rule');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('product_user_image_rules')) {
            $schema->dropTable('product_user_image_rules');
        }
        if ($schema->hasTable('products')) {
            $table = $schema->getTable('products');
            if ($table->hasColumn('customer_note_mode')) {
                $table->dropColumn('customer_note_mode');
            }
        }
    }
};
