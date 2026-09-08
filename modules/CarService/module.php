<?php

declare(strict_types=1);

return [
    'id' => 'carservice',
    'name' => 'CarService',
    'icon' => 'build',
    'version' => '1.13.5',
    'description' => 'module.carservice.description',
    'class' => Modules\CarService\CarServiceModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0', 'fleet' => '^1.0', 'customers' => '^1.0', 'currency' => '^1.1'],
    'optional' => ['media' => '^1.0', 'documents' => '^1.0', 'calendar' => '^1.0', 'idoklad' => '^1.0', 'moneys3' => '^1.0', 'partscatalog' => '^1.0', 'esign' => '^1.0'],
    'groups' => ['ecs'],
    'provides' => ['carservice'],
    'capabilities' => ['carservice', 'carservice.branch_context'],
    'permissions' => [
        ['name' => 'carservice.view', 'label' => 'perm.carservice.view', 'group' => 'perm.group.carservice'],
        ['name' => 'carservice.manage', 'label' => 'perm.carservice.manage', 'group' => 'perm.group.carservice'],
        ['name' => 'carservice.tracker', 'label' => 'perm.carservice.tracker', 'group' => 'perm.group.carservice'],
    ],
];
