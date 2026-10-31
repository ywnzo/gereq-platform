<?php

declare(strict_types=1);

return [
    'id' => 'cookie',
    'name' => 'Cookie',
    'icon' => 'cookie',
    'version' => '0.2.0',
    'description' => 'module.cookie.description',
    'class' => Modules\Cookie\CookieModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0'],
    'optional' => [],
    'provides' => ['cookie'],
    'capabilities' => ['cookie'],
    'permissions' => [
        ['name' => 'cookie.manage', 'label' => 'perm.cookie.manage', 'group' => 'perm.group.cookie'],
    ],
];
