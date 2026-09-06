<?php
declare(strict_types=1);
return [
    'id' => 'products',
    'name' => 'Products',
    'version' => '1.7.4',
    'description' => 'module.products.description',
    'class' => Modules\Products\ProductsModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0', 'currency' => '^1.1'],
    'optional' => ['media' => '^1.0'],
    'provides' => ['products'],
    'capabilities' => ['products'],
    'permissions' => [
        ['name' => 'products.view', 'label' => 'perm.products.view', 'group' => 'perm.group.products'],
        ['name' => 'products.manage', 'label' => 'perm.products.manage', 'group' => 'perm.group.products'],
    ],
];