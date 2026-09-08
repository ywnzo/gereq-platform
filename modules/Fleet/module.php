<?php

declare(strict_types=1);

return [
    'id' => 'fleet',
    'name' => 'Fleet',
    'icon' => 'car',
    'version' => '1.7.7',
    'description' => 'module.fleet.description',
    'class' => Modules\Fleet\FleetModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0'],
    'optional' => ['customers' => '^1.0', 'media' => '^1.0', 'calendar' => '^1.0'],
    'groups' => ['ecs'],
    'provides' => ['fleet'],
    'capabilities' => ['fleet'],
    'permissions' => [
        ['name' => 'fleet.view', 'label' => 'perm.fleet.view', 'group' => 'perm.group.fleet'],
        ['name' => 'fleet.manage', 'label' => 'perm.fleet.manage', 'group' => 'perm.group.fleet'],
    ],
];
