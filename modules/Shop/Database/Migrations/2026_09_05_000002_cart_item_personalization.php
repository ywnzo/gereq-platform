<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('shop_cart_items')) {
            return;
        }
        $table = $schema->getTable('shop_cart_items');
        if (!$table->hasColumn('item_note')) {
            $table->addColumn('item_note', Types::TEXT)->setNotnull(false);
        }
        if (!$table->hasColumn('meta_json')) {
            $table->addColumn('meta_json', Types::TEXT)->setNotnull(false);
        }
        // Allow multiple personalized lines of the same product.
        foreach ($table->getIndexes() as $index) {
            if ($index->isUnique() && $index->getColumns() === ['cart_id', 'product_id', 'variant_id']) {
                $table->dropIndex($index->getName());
            }
        }
        if ($table->hasIndex('uniq_shop_cart_item_variant')) {
            $table->dropIndex('uniq_shop_cart_item_variant');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('shop_cart_items')) {
            return;
        }
        $table = $schema->getTable('shop_cart_items');
        if ($table->hasColumn('item_note')) {
            $table->dropColumn('item_note');
        }
        if ($table->hasColumn('meta_json')) {
            $table->dropColumn('meta_json');
        }
    }
};
