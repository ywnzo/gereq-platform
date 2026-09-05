<?php

declare(strict_types=1);

return [
    'id' => 'shop',
    'name' => 'Shop',
    'version' => '1.1.1',
    'description' => 'module.shop.description',
    'class' => Modules\Shop\ShopModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
        'products' => '^1.0',
        'currency' => '^1.0',
    ],
    'optional' => [
        'orders' => '^1.0',
        'customers' => '^1.0',
        'warehouse' => '^1.0',
    ],
    'provides' => ['shop'],
    'capabilities' => ['shop'],
    'permissions' => [
        ['name' => 'shop.view', 'label' => 'perm.shop.view', 'group' => 'perm.group.shop'],
        ['name' => 'shop.manage', 'label' => 'perm.shop.manage', 'group' => 'perm.group.shop'],
    ],
];
