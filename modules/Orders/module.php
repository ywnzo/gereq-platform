<?php

declare(strict_types=1);

return [
    'id' => 'orders',
    'name' => 'Orders',
    'version' => '1.2.1',
    'description' => 'module.orders.description',
    'class' => Modules\Orders\OrdersModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
        'customers' => '^1.0',
        'products' => '^1.0',
        'currency' => '^1.0',
    ],
    'optional' => [
        'documents' => '^1.0',
        'notifications' => '^1.0',
    ],
    'provides' => ['orders'],
    'capabilities' => ['orders'],
    'permissions' => [
        ['name' => 'orders.view', 'label' => 'perm.orders.view', 'group' => 'perm.group.orders'],
        ['name' => 'orders.manage', 'label' => 'perm.orders.manage', 'group' => 'perm.group.orders'],
        ['name' => 'invoices.view', 'label' => 'perm.invoices.view', 'group' => 'perm.group.orders'],
        ['name' => 'invoices.manage', 'label' => 'perm.invoices.manage', 'group' => 'perm.group.orders'],
    ],
];