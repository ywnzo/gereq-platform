<?php

declare(strict_types=1);

return [
    'id' => 'shop',
    'name' => 'Shop',
    'icon' => 'bag',
    'version' => '1.5.0',
    'description' => 'module.shop.description',
    'class' => Modules\Shop\ShopModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
        'products' => '^1.2',
        'currency' => '^1.1',
    ],
    'optional' => [
        'orders' => '^1.0',
        'customers' => '^1.0',
        'warehouse' => '^1.0',
        'customer_uploads' => '^1.0',
        'packeta' => '^1.0',
    ],
    'provides' => ['shop'],
    'capabilities' => ['shop', 'shipping_carriers'],
    'permissions' => [
        ['name' => 'shop.view', 'label' => 'perm.shop.view', 'group' => 'perm.group.shop'],
        ['name' => 'shop.manage', 'label' => 'perm.shop.manage', 'group' => 'perm.group.shop'],
    ],
];
