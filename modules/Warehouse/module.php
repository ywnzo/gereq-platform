<?php

declare(strict_types=1);

return [
    'id' => 'warehouse',
    'name' => 'Warehouse',
    'icon' => 'warehouse',
    'version' => '1.0.2',
    'description' => 'module.warehouse.description',
    'class' => Modules\Warehouse\WarehouseModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
        'products' => '^1.0',
    ],
    'optional' => [],
    'provides' => ['inventory'],
    'capabilities' => ['inventory'],
    'permissions' => [
        ['name' => 'warehouse.view', 'label' => 'perm.warehouse.view', 'group' => 'perm.group.warehouse'],
        ['name' => 'warehouse.manage', 'label' => 'perm.warehouse.manage', 'group' => 'perm.group.warehouse'],
        ['name' => 'warehouse.stock.manage', 'label' => 'perm.warehouse.stock.manage', 'group' => 'perm.group.warehouse'],
    ],
];
