<?php

declare(strict_types=1);

return [
    'id' => 'carservice',
    'name' => 'CarService',
    'version' => '1.1.0',
    'description' => 'module.carservice.description',
    'class' => Modules\CarService\CarServiceModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0', 'fleet' => '^1.0', 'customers' => '^1.0'],
    'optional' => ['media' => '^1.0', 'documents' => '^1.0', 'calendar' => '^1.0', 'idoklad' => '^1.0', 'moneys3' => '^1.0'],
    'provides' => ['carservice'],
    'capabilities' => ['carservice'],
    'permissions' => [
        ['name' => 'carservice.view', 'label' => 'perm.carservice.view', 'group' => 'perm.group.carservice'],
        ['name' => 'carservice.manage', 'label' => 'perm.carservice.manage', 'group' => 'perm.group.carservice'],
        ['name' => 'carservice.tracker', 'label' => 'perm.carservice.tracker', 'group' => 'perm.group.carservice'],
    ],
];
