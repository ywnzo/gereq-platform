<?php

declare(strict_types=1);

return [
    'id' => 'product_discounts',
    'name' => 'Product Discounts',
    'version' => '1.1.5',
    'description' => 'module.product_discounts.description',
    'class' => Modules\ProductDiscounts\ProductDiscountsModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
        'products' => '^1.0',
        'currency' => '^1.1',
    ],
    'optional' => [],
    'provides' => ['product_discounts'],
    'capabilities' => ['product_discounts'],
    'permissions' => [
        ['name' => 'product_discounts.view', 'label' => 'perm.product_discounts.view', 'group' => 'perm.group.product_discounts'],
        ['name' => 'product_discounts.manage', 'label' => 'perm.product_discounts.manage', 'group' => 'perm.group.product_discounts'],
    ],
];
