<?php

declare(strict_types=1);

return [
    'id' => 'returns',
    'name' => 'Returns',
    'icon' => 'undo',
    'version' => '1.0.3',
    'description' => 'module.returns.description',
    'class' => Modules\Returns\ReturnsModule::class,
    'author' => 'GEREQ',
    'requires' => ['core' => '^1.0', 'currency' => '^1.1'],
    'optional' => [
        'orders' => '^1.0',
        'shop' => '^1.0',
        'warehouse' => '^1.0',
    ],
    'provides' => ['returns'],
    'capabilities' => ['returns'],
    'permissions' => [
        ['name' => 'returns.view', 'label' => 'perm.returns.view', 'group' => 'perm.group.returns'],
        ['name' => 'returns.manage', 'label' => 'perm.returns.manage', 'group' => 'perm.group.returns'],
    ],
];
