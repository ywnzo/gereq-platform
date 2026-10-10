<?php

declare(strict_types=1);

return [
    'id' => 'authorization',
    'name' => 'Autorizace',
    'icon' => 'verified_user',
    'version' => '1.0.0',
    'description' => 'module.authorization.description',
    'class' => Modules\Authorization\AuthorizationModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [],
    'provides' => ['authorization', 'oauth'],
    'capabilities' => ['oauth'],
    'permissions' => [
        ['name' => 'authorization.view', 'label' => 'perm.authorization.view', 'group' => 'perm.group.authorization'],
        ['name' => 'authorization.manage', 'label' => 'perm.authorization.manage', 'group' => 'perm.group.authorization'],
    ],
];
