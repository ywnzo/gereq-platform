<?php

declare(strict_types=1);

return [
    'id' => 'customers',
    'name' => 'Customers',
    'version' => '1.1.1',
    'description' => 'module.customers.description',
    'class' => Modules\Customers\CustomersModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [
        'notifications' => '^1.0',
    ],
    'provides' => ['contacts'],
    'capabilities' => ['contacts'],
    'permissions' => [
        ['name' => 'customers.view', 'label' => 'perm.customers.view', 'group' => 'perm.group.customers'],
        ['name' => 'customers.create', 'label' => 'perm.customers.create', 'group' => 'perm.group.customers'],
        ['name' => 'customers.edit', 'label' => 'perm.customers.edit', 'group' => 'perm.group.customers'],
        ['name' => 'customers.delete', 'label' => 'perm.customers.delete', 'group' => 'perm.group.customers'],
    ],
];
