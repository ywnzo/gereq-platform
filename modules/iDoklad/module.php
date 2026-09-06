<?php

declare(strict_types=1);

return [
    'id' => 'idoklad',
    'name' => 'iDoklad',
    'version' => '1.2.0',
    'description' => 'module.idoklad.description',
    'class' => Modules\iDoklad\iDokladModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [
        'customers' => '^1.0',
        'orders' => '^1.0',
        'currency' => '^1.1',
    ],
    'groups' => ['ecs'],
    'provides' => ['idoklad'],
    'capabilities' => ['idoklad'],
    'permissions' => [
        ['name' => 'idoklad.view', 'label' => 'perm.idoklad.view', 'group' => 'perm.group.idoklad'],
        ['name' => 'idoklad.manage', 'label' => 'perm.idoklad.manage', 'group' => 'perm.group.idoklad'],
        ['name' => 'idoklad.sync', 'label' => 'perm.idoklad.sync', 'group' => 'perm.group.idoklad'],
    ],
];
